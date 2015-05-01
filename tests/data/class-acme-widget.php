<?php

namespace CustomizeWidgetsPlus;

class Acme_Widget extends \WP_Widget {

	function __construct(  ) {
		parent::__construct(
			'acme',
			array(
				'name' => 'Acme',
			)
		);
	}
}

