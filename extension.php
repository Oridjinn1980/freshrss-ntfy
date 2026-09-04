<?php

class NtfyExtension extends Minz_Extension {

	private $cache = [];

	private $debug = false;

	public function init(): void {
		parent::init();

		$this->registerTranslates();
		$this->registerHook('entry_before_insert', [$this, 'newEntry']);
		$this->registerHook('js_vars', [$this, 'jsVars']);
		register_shutdown_function([$this, 'shutdownHandler']);

		Minz_View::appendScript($this->getFileUrl('ntfy.js'),'','','');
	}

	public function handleConfigureAction(): void {
		if (Minz_Request::isPost()) {
			if (Minz_Request::paramStringNull('ntfy') === "feed") {
				$this->saveFeed();
			}
			else {
				$this->saveConfig();
			}
		}
	}

	private function saveConfig(): void {
		$config = $this->getUserConfiguration();

		$config['server'] = trim(trim(Minz_Request::paramString("server")), '/');
		$config['auth_token'] = trim(Minz_Request::paramString("auth_token"));
		$config['default_topic'] = trim(trim(Minz_Request::paramString("default_topic")), '/');
		$config['aggregate'] = Minz_Request::paramBoolean("aggregate");

		$this->setUserConfiguration($config);
	}

	private function saveFeed(): void {
		$feedId = Minz_Request::paramIntNull('feed_id');
		$topic = Minz_Request::paramStringNull('topic');
		$topic = trim(trim($topic), '/');

		$config = $this->getUserConfiguration();
		$config['feeds'][$feedId] = [
			'topic' => $topic,
		];
		$this->setUserConfiguration($config);
	}

	public function newEntry(FreshRSS_Entry $entry): FreshRSS_Entry {
		$feed = $entry->feed();

		if ($entry->isUpdated()) return $entry;

		$feedId = $feed->id();
		if (!isset($this->cache[$feedId])) {
			$this->cache[$feedId] = [
				'count' => 0,
				'excerpts' => [],
			];
		}
		$this->cache[$feedId]['count'] += 1;

		$content = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($entry->content(false)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
		if ($content !== '') {
			$words = explode(' ', $content);
			$excerpt = implode(' ', array_slice($words, 0, 200));
			$this->cache[$feedId]['excerpts'][] = $excerpt;
		}

		return $entry;
	}

	public function shutdownHandler(): void {
		if(empty($this->cache)) return;

		$config = $this->getUserConfiguration();
		$server = $config['server'] ?? null;
		$defaultTopic = $config['default_topic'] ?? null;

		if (!$server || !$defaultTopic) return;

		$feedDAO = FreshRSS_Factory::createFeedDao();
		$total = 0;

		foreach ($this->cache as $feedId => $feedData) {
			$feedCount = $feedData['count'];
			$feed = $feedDAO->searchById($feedId);
			$topic = $config['feeds'][$feedId]['topic'] ?? null;
			if ($topic === null) {
				if ($config['aggregate']) {
					$total += $feedCount;
					$aggregateExcerpts = array_merge($aggregateExcerpts ?? [], $feedData['excerpts']);
					continue;
				}
				$topic = $defaultTopic;
			}
			$feedName = $feed->name();
			$message = "'$feedName' has $feedCount new article(s)";
			if (!empty($feedData['excerpts'])) {
				$message .= "\n\n" . implode("\n\n", $feedData['excerpts']);
			}
			$this->sendNotification("$server/$topic", $message, $config['auth_token'] ?? null);
		}

		if($config['aggregate'] && $total > 0) {
			$message = "Your feeds have $total new article(s)";
			if (!empty($aggregateExcerpts)) {
				$message .= "\n\n" . implode("\n\n", $aggregateExcerpts);
			}
			$this->sendNotification("$server/$defaultTopic", $message, $config['auth_token'] ?? null);
		}
	}

	private function sendNotification(string $url, string $content, ?string $authToken): void {
		$headers = ['Content-Type: text/plain'];
		if ($authToken !== null && $authToken !== '') {
			$headers[] = 'Authorization: Bearer ' . $authToken;
		}

		file_get_contents($url, false, stream_context_create([
			'http' => [
				'method' => 'POST', // PUT also works
				'header' => implode("\r\n", $headers),
				'content' => $content,
			]
		]));
	}

	public function jsVars(array $vars): array {
		$vars['ntfy_ext_name'] = $this->getName();
		$vars['ntfy_feed_config_html'] = file_get_contents(__DIR__ . '/static/feed_config.html');
		$vars['ntfy_feeds'] = $this->getUserConfiguration()['feeds'] ?? [];
		return $vars;
	}

	function extensionLog(string $data) {
		if (!$this->debug) return;
		syslog(LOG_INFO, "ntfy: " . $data);
	}
}
