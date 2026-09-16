<?php declare(strict_types = 1);

$ignoreErrors = [];
$ignoreErrors[] = [
	'message' => '#^Call to an undefined method Neos\\\\ContentRepository\\\\Domain\\\\Service\\\\Context\\:\\:getCurrentSiteNode\\(\\)\\.$#',
	'identifier' => 'method.notFound',
	'count' => 1,
	'path' => __DIR__ . '/Classes/Controller/FormApiController.php',
];
$ignoreErrors[] = [
	'message' => '#^Call to an undefined method Neos\\\\Eel\\\\FlowQuery\\\\FlowQuery\\:\\:find\\(\\)\\.$#',
	'identifier' => 'method.notFound',
	'count' => 6,
	'path' => __DIR__ . '/Classes/Controller/FormApiController.php',
];

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
