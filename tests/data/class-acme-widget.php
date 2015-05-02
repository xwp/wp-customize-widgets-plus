<?php

namespace CustomizeWidgetsPlus;

class Acme_Widget extends \WP_Widget {
	const ID_BASE = 'acme';

	function __construct(  ) {
		parent::__construct(
			self::ID_BASE,
			array(
				'name' => 'Acme',
			)
		);
	}
}

