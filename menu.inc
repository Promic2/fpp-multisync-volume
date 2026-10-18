<?php
// One entry: the settings page under Content Setup.
$menuEntries = Array(
	Array(
		'text' => 'MultiSync Volume',
		'type' => 'content',
		'page' => 'content.php',
		'wrap' => 1
	),
);

// $plugin and $menu are set by FPP before this file is included.
foreach ($menuEntries as $entry)
{
	if ($entry['type'] != $menu)
		continue;

	$nopage = '';
	if (isset($entry['wrap']) && ($entry['wrap'] == 0))
		$nopage = '&nopage=1';

	printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n",
		$plugin, $entry['page'], $nopage, $entry['text']);
}
?>
