<?php

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Dzahdev\\Recom\\Controller',
		],
		'readonly' => true,
	],
	'console' => [
		'value' => [
			'commands' => [
				\Dzahdev\Recom\Cli\TrainCommand::class,
			],
		],
		'readonly' => true,
	],
];
