<?php
error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors', true);
ini_set('html_errors', false);
set_time_limit(0);

define('DATALIFEENGINE', true);
define('ROOT_DIR', __DIR__);
define('ENGINE_DIR', ROOT_DIR . '/engine');

@include (ENGINE_DIR . '/data/config.php');
date_default_timezone_set($config['date_adjust']);
require_once __DIR__ . '/language/Russian/website.lng';
setlocale(LC_NUMERIC, "C");

require_once ENGINE_DIR . '/classes/mysql.php';
require_once ENGINE_DIR . '/data/dbconfig.php';
require_once ENGINE_DIR . '/modules/functions.php';

if (!isset($argv)) {
	echo 'php -f ' . __DIR__ . $_SERVER['SCRIPT_NAME'] . ' > ' . ROOT_DIR . '/engine/data/kprate.log 2>&1 &';
	die();
}

/* В правой части указать имя соответствующего доп.поля */
$fields = [
	'kinopoisk_id'	=> 'kinopoisk_id', //ID кинопоиска
	'kp_rate'		=> 'kp_rate', //рейтинг кинопоиска
	'kp_votes'		=> 'kp_votes', //количество голосов кинопоиска
	'kp_rate_vote'	=> '', //сборная строка вида: "7.87 (4568)"
	'imdb_rate'		=> 'imdb_rate', //рейтинг imdb
	'imdb_votes'	=> 'imdb_votes', //количество голосов imdb
	'imdb_rate_vote'=> '', //сборная строка вида: "8.765 (56874)"
];

function xfieldsdatasave(array $xfields): string
{
	global $db;
	$filecontents = [];
	foreach ($xfields as $xfielddataname => $xfielddatavalue) {
		if ($xfielddatavalue === '') continue;
		$xfielddataname = str_replace( "|", "&#124;", $xfielddataname);
		$xfielddataname = str_replace( "\r\n", "__NEWL__", $xfielddataname);
		$xfielddatavalue = str_replace( "|", "&#124;", $xfielddatavalue);
		$xfielddatavalue = str_replace( "\r\n", "__NEWL__", $xfielddatavalue);
		$filecontents[] = "$xfielddataname|$xfielddatavalue";
	}
	$filecontents = $db->safesql(join('||', $filecontents ));
	return $filecontents;
}

function getContent(int $kinopoisk_id): string
{
	$ch = curl_init('https://rating.kinopoisk.ru/' . $kinopoisk_id . '.xml');
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER	=> true,
		CURLOPT_HEADER			=> false,
		CURLOPT_FOLLOWLOCATION	=> true,
		CURLOPT_MAXREDIRS		=> 1,
		CURLOPT_CONNECTTIMEOUT	=> 5,
		CURLOPT_TIMEOUT			=> 5,
	]);
	$data = curl_exec($ch);
	curl_close($ch);
	return $data;
}

function getRatings(int $kinopoisk_id): array
{
	$ratings = [
		'kp_rate'		=> '',
		'kp_votes'		=> '',
		'imdb_rate'		=> '',
		'imdb_votes'	=> '',
	];

	$xml_data = getContent($kinopoisk_id);

	if (preg_match("#<kp_rating num_vote=\"(\d+)\">(.+?)</kp_rating>#i", $xml_data, $data)) {
		if (($rate = (float)$data[2]) > 0) {
			$ratings['kp_rate']  = $rate;
			$ratings['kp_votes'] = (int)$data[1];
		}
	}

	if (preg_match("#<imdb_rating num_vote=\"(\d+)\">(.+?)</imdb_rating>#i", $xml_data, $data)) {
		if (($rate = (float)$data[2]) > 0) {
			$ratings['imdb_rate']  = $rate;
			$ratings['imdb_votes'] = (int)$data[1];
		}
	}

	return $ratings;
}

$sql = $db->query(sprintf('SELECT id, xfields FROM %s_post', PREFIX));
while ($row = $db->get_row($sql)) {
	$xfields = xfieldsdataload(stripslashes($row['xfields']));

	if ($kinopoisk_id = (int)$xfields[$fields['kinopoisk_id']]) {
		$ratings = getRatings($kinopoisk_id);

		//$ratings['kp_rate'] = round($ratings['kp_rate'], 2); //округлять рейтинг до 2го знака после запятой
		//$ratings['imdb_rate'] = round($ratings['imdb_rate'], 2);

		foreach ($ratings as $k => $v) {
			$fields[$k] && $xfields[$fields[$k]] = $v;
		}
		
		if ($fields['kp_rate_vote'] && $ratings['kp_votes']) {
			$xfields[$fields['kp_rate_vote']] = sprintf('%s (%d)', $ratings['kp_rate'], $ratings['kp_votes']);
		}
		if ($fields['imdb_rate_vote'] && $ratings['imdb_votes']) {
			$xfields[$fields['imdb_rate_vote']] = sprintf('%s (%d)', $ratings['imdb_rate'], $ratings['imdb_votes']);
		}

		$filecontents = xfieldsdatasave($xfields);
		$db->query(sprintf('UPDATE %s_post SET xfields="%s" WHERE id = %d', PREFIX, $filecontents, $row['id']));
		$db->close();

		echo $row['id'] . ' - ' . json_encode($ratings) . PHP_EOL;
		usleep(500000);
	}
}

echo 'Готово';
