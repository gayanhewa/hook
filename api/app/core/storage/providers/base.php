<?php
namespace Storage\Providers;

class Base {

	public function upload($path, $file, $options=array()) {
		throw new \Exception("'upload' not implemented on this provider.");
	}

}
