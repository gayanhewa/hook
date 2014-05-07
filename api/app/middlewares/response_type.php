<?php

class ResponseTypeMiddleware extends \Slim\Middleware
{
	const MAX_POOLING_RETRY = 60; // 60 seconds
	const MIN_POOLING_RETRY = 0; // 1 second

	const MAX_REFRESH_TIMEOUT = 40; // 40 seconds
	const MIN_REFRESH_TIMEOUT = 1; // 1 seconds

	public function call()
	{
		// The Slim application
		$app = $this->app;

		//
		// Respond based on ACCEPT request header
		// Add EventSource middeware: http://en.wikipedia.org/wiki/Server-sent_events | http://www.html5rocks.com/en/tutorials/eventsource/basics/
		//
		if (($app->request->headers->get('ACCEPT') == 'text/event-stream') || // Checking for ACCEPT header is smarter.
				$app->request->getMethod() == 'GET' && preg_match('/^\/channels/', $app->request->getResourceUri())) { // Workaround for Internet Explorer, which can't send custom request headers on CORS requests.
			ini_set('zlib.output_compression', 0);
			ini_set('implicit_flush', 1);

			$pool_start = $app->request->headers->get('X-Time') ?: time();

			// stream timing configs
			$stream_config = $app->request->get('stream');
			$refresh_timeout = (isset($stream_config['refresh'])) ? intval($stream_config['refresh']) : self::MIN_REFRESH_TIMEOUT;
			$refresh_timeout = clamp($refresh_timeout, self::MIN_REFRESH_TIMEOUT, self::MAX_REFRESH_TIMEOUT);
			$retry_timeout = ((isset($stream_config['retry'])) ? intval($stream_config['retry']) : self::MAX_POOLING_RETRY);
			$retry_timeout = clamp($retry_timeout, self::MIN_POOLING_RETRY, self::MAX_POOLING_RETRY) * 1000;

			$last_event_id = $app->request->headers->get('Last-Event-ID') ?: $app->request->get('lastEventId');

			// Get last collection event id when 'only_new' option is set
			// if ($app->request->get('only_new')) {
			// 	$last_event_id = models\Collection::query()
			// 		->from(basename($app->request->getResourceUri()))
			// 		->orderBy('_id', 'desc')
			// 		// ->offset(1)
			// 		->first()
			// 		->_id;
			// }

			// Set response headers
			$app->response->headers->set('Content-type', 'text/event-stream');
			$app->response->headers->set('Cache-Control', 'no-cache');
			foreach($app->response->headers as $header => $content) {
				header("{$header}: {$content}");
			}

			echo 'retry: '. $retry_timeout . PHP_EOL . PHP_EOL;

			do {
				// Close EventSource connection after 15 seconds
				// let the client re-open it if necessary
				if ((time() - $pool_start) > 15) {
					die();
				}

				// Append last-event-id to filtering options
				if ($last_event_id) {
					$query_data = AppMiddleware::decode_query_string();
					if (!isset($query_data['q'])) {
						$query_data['q'] = array();
					}

					if ($last_event_id) {
						array_push($query_data['q'], array('_id', '>', $last_event_id));
					}

					$app->environment->offsetSet('slim.request.query_hash', $query_data);
				}

				try {
					// Call current request
					$this->next->call();
				} catch (Exception $e) {
					$app->content = $this->handle_error_response($e, $app);
				}

				// Multiple results
				if (method_exists($app->content, 'each')) {
					$self = $this;
					$app->content->each(function($data) use ($app, &$last_event_id, &$self) {
						echo 'id: '. $data->_id . PHP_EOL . PHP_EOL;
						echo 'data: '. $self->encode_content($data) . PHP_EOL . PHP_EOL;
						ob_flush();
						flush();
						$last_event_id = $data->_id;
					});

				} else {
					// Single result
					if ($app->content instanceof stdClass) {
						echo 'id: '. $app->content->_id . PHP_EOL . PHP_EOL;
						$last_event_id = $data->content->_id;
					}
					echo 'data: '. $this->encode_content($app->content) . PHP_EOL . PHP_EOL;
					ob_flush();
					flush();
				}

				sleep($refresh_timeout);
			} while (true);

		} else {

			try {
				// Call current request
				$this->next->call();
			} catch (Exception $e) {
				$app->content = $this->handle_error_response($e, $app);
			}

			// return 404 status code when 'content' is null or false.
			// probably something is wrong. It's better that we shout it for you.
			if (!$app->content) {
				$app->response->setStatus(404);
			} else {
				$app->response->headers->set('Content-type', 'application/json');
				$app->response->setBody($this->encode_content($app->content));
			}


		}

	}

	public function encode_content($content) {
		if (method_exists($content, 'toJson')) {
			return $content->toJson();
		} else {
			return json_encode($content);
		}
	}

	protected function handle_error_response($e, $app) {
		$message = $e->getMessage();

		$app->log->info("Error: '{$message}'");
		$app->log->info($e->getTraceAsString());

		file_put_contents('php://stderr', "[[ dl-api: error ]] " . $message . PHP_EOL . $e->getTraceAsString() . PHP_EOL);

		if (strpos($message, "column not found") !== false ||        // mysql
				strpos($message, "no such table") !== false ||           // mysql
				strpos($message, "has no column named") !== false ||     // sqlite
				strpos($message, "table or view not found") !== false) { // sqlite
			return array();

		} else {
			$code = intval($e->getCode());
			if (!$code || $code < 200 || $code > 500) {
				$code = 500;
			}
			$app->response->setStatus($code);

			return array('error' => $message);
		}
	}

}
