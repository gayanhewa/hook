<?php
namespace Auth\Providers;

class Facebook extends Base {

	public function authenticate($data) {
		$data = $this->requestFacebookGraph($data);

		$user = null;
		try {
			$user = $this->find('facebook_id', $data);
		} catch (\Illuminate\Database\QueryException $e) {}

		if (!$user) {
			$user = \models\Auth::create($data);
		}

		return $user->dataWithToken();
	}

	public function verify($data) {
		$userdata = null;
		if ($user = $this->find('facebook_id', $this->requestFacebookGraph($data))) {
			$userdata = $user->dataWithToken();
		}
		return $userdata;
	}

	protected function requestFacebookGraph($data) {
		// validate accessToken
		if (!isset($data['accessToken'])) {
			throw new \Exception(__CLASS__ . ": you must provide user 'accessToken'.");
		}

		$app_id = $data['app_id'];

		$client = new \Guzzle\Http\Client("https://graph.facebook.com");
		$response = $client->get("/me?access_token={$data['accessToken']}")->send();
		$facebook_data = json_decode($response->getBody(), true);

		// Filter fields from Facebook that isn't whitelisted for auth.
		$field_whitelist = array('id', 'email', 'first_name', 'gender', 'last_name', 'link', 'locale', 'name', 'timezone', 'username');
		foreach($facebook_data as $field => $value) {
			if (!in_array($field, $field_whitelist)) {
				unset($facebook_data[$field]);
			}
		}

		// Merge given data with facebook data
		$data = array_merge($data, $facebook_data);

		// rename 'facebook_id' field
		$data['app_id'] = $app_id;
		$data['facebook_id'] = $data['id'];
		unset($data['id']);

		return $data;
	}

}
