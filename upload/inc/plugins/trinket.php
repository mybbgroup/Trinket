<?php
// TODO: Purge cache on user delete / modify : Admin / Mod
if (!defined("IN_MYBB")) {
	die();
}

$plugins->add_hook('global_end', 'trinket_welcome');
$plugins->add_hook("index_start", "trinket_newbie");
$plugins->add_hook("index_end", "trinket_index");
$plugins->add_hook("showteam_user", "trinket_teamavatar");
$plugins->add_hook("build_forumbits_forum", "trinket_forumbits");
$plugins->add_hook("forumdisplay_thread_end", "trinket_bits");
$plugins->add_hook("search_results_thread", "trinket_bits");
$plugins->add_hook("search_results_post", "trinket_bits");
$plugins->add_hook("xmlhttp", "trinket_profilepop");
$plugins->add_hook("usercp_do_avatar_end", "trinket_purgecache");
$plugins->add_hook("admin_user_users_edit_start", "trinket_purgecache");
$plugins->add_hook("modcp_do_editprofile_start", "trinket_purgecache");

function trinket_info()
{
	return [
		"name"          => 'Trinket',
		"description"   => 'User link enhancement',
		"website"       => "https://eff.one",
		"author"        => "effone",
		"authorsite"    => "https://eff.one",
		"version"       => "1.0.0",
		"compatibility" => "18*"
	];
}

function trinket_activate()
{
	require_once MYBB_ROOT . "/inc/adminfunctions_templates.php";
	find_replace_templatesets('header_welcomeblock_member', '#{\$lang->welcome_back}#', '<!-- start: header_welcome_back -->{\$lang->welcome_back}<!-- end: header_welcome_back -->');
	find_replace_templatesets('index_whosonline_memberbit', '#' . preg_quote('{$user[\'profilelink\']}') . '#', '<!-- start: index_onlineuser -->{$user[\'profilelink\']}<!-- end: index_onlineuser -->');
}

function trinket_deactivate()
{
	require_once MYBB_ROOT . "/inc/adminfunctions_templates.php";
	$templates = array('header_welcomeblock_member', 'index_stats', 'index_whosonline_memberbit');
	foreach ($templates as $template) {
		find_replace_templatesets($template, '#<!--(.*?)-->#', '');
	}
	trinket_purgecache(-1);
}

function trinket_fetch_key($targetvalue, $field, $arraydata)
{
	return (int)array_search($targetvalue, array_map(function ($data) use ($field) {
		return $data[$field];
	}, $arraydata));
}

function trinket_fetch_user($id = 0, $name = false, $class = [], $fields = [], $use_cache = true)
{
	$user = [];
	if (!$id && !$name) return $user;
	$cache_updated = false;
	$required_fields = array_merge(['uid', 'username', 'usergroup', 'displaygroup', 'avatar'], $fields);

	if ($use_cache) {
		static $userdata;
		if (empty($userdata)) $userdata = [];
		$uid = $name ? trinket_fetch_key($id, 'username', $userdata) : (int)$id;
		if ($uid && isset($userdata[$uid])) {
			$user = $userdata[$uid];
		} else {
			global $cache;
			$trinket = $cache->read('trinket');
			if (empty($trinket)) $trinket = [];
			$uid = $name ? trinket_fetch_key($id, 'username', $trinket) : (int)$id;
			if ($uid && isset($trinket[$id])) {
				$user = $userdata[$uid] = $trinket[$uid];
			}
		}
	}

	if (empty($user)) {
		if ($use_cache) $cache_updated = true;
		$user = $name ? get_user_by_username($id, ['fields' => $required_fields]) : array_intersect_key(get_user((int)$id), array_flip($required_fields));
		$userdata[$user['uid']] = $trinket[$user['uid']] = $user;
	}

	if ($cache_updated) $cache->update('trinket', $trinket);

	if (!empty($user)) {
		global $mybb;
		$user['profilelink_formatted'] = build_profile_link(format_name(htmlspecialchars_uni($user['username']), $user['usergroup'], $user['displaygroup']), $user['uid']);
		if($mybb->settings['trinket_profilepop']) $user['profilelink_formatted'] = preg_replace('/<a(.*?)>/', "<a data-uid='".$user['uid']."'$1>", $user['profilelink_formatted'], 1);
		if (empty($user['avatar'])) {
			$mybb->settings['trinket_forcedefaultavatar'] = true; // SETTINGS
			if (empty($mybb->settings['useravatar'])) {
				if ($mybb->settings['trinket_forcedefaultavatar']) $user['avatar'] = 'images/default_avatar.png';
			} else {
				global $theme;
				$user['avatar'] = str_replace('{theme}', $theme['imgdir'], $mybb->settings['useravatar']);
			}
		}
	}

	if (!empty($user['avatar'])) {
		if (!is_array($class)) $class = explode(',', preg_replace('/\s+/', '', $class));
		$class[] = 'trinket';
		$user['avatar_html'] = '<img src="' . $user['avatar'] . '" class="' . implode(' ', $class) . '" alt="' . $user['username'] . '">';
	}
	return $user;
}

function trinket_newbie()
{
	global $cache, $newbie;
	$stats = $cache->read('stats');
	$newbie = trinket_fetch_user($stats['lastuid']);
}

function trinket_welcome()
{
	global $lang, $mybb, $header;
	if ((int) $mybb->user['uid']) {
		$mybb->settings['trinket_welcomeavatar'] = true; // SETTING
		$mybb->user = array_merge(trinket_fetch_user((int) $mybb->user['uid']), $mybb->user); // Replace avatar, include profilelink_formatted
		$user = $mybb->settings['trinket_welcomeavatar'] ? trinket_inline((int) $mybb->user['uid']) : $mybb->user['profilelink_formatted'];
		$lang->welcome_back = preg_replace('#(<a)[\s\S]+(<\/a>)#', $user, $lang->welcome_back);
		$header = preg_replace('#(<!-- start: header_welcome_back)[\s\S]+(end: header_welcome_back -->)#', $lang->welcome_back, $header);
	}
}

function trinket_index()
{
	global $mybb, $cache, $boardstats, $newbie, $onlinebots;
	$stats = $cache->read('stats');
	$newbie = trinket_fetch_user($stats['lastuid']);

	// Set online bot avatars
	if (!empty($onlinebots)) {
		$spavpath = $mybb->settings['avataruploadpath'];
		if (my_substr($spavpath, 0, 1) == '.') {
			$spavpath = substr($spavpath, 2);
		}
		$spavpath .= "/spiders/";

		$spiders = $cache->read('spiders');
		foreach ($onlinebots as $formatted_name) {
			$name = trim(strip_tags($formatted_name));
			$bot_avatar = glob(MYBB_ROOT . $spavpath . trinket_fetch_key($name, 'name', $spiders) . '.*');
			$bot_avatar = empty($bot_avatar) ? $mybb->settings['bburl'] . '/' . $spavpath . '0.png' : str_replace(MYBB_ROOT, $mybb->settings['bburl'] . '/', $bot_avatar[0]);
			$bot_avatar = '<img class="trinket inline" src="' . $bot_avatar . '" alt="' . $name . '"/>';
			$boardstats = str_replace($formatted_name, '<span class="trinket-inline">' . $bot_avatar . $formatted_name . "</span>", $boardstats);
		}
	}

	// Set online user avatars
	$replace = [];
	preg_match_all('/<!--\ss.+?onlineuser\s-->(.*?)<!--\se.*?onlineuser\s-->/', $boardstats, $matches);

	for ($i = 0; $i < count($matches[1]); $i++) {
		$replace[] = trinket_inline(trim(strip_tags($matches[1][$i])), true);
	}
	$boardstats = preg_replace_callback('/<!--\ss.+?onlineuser\s-->(.*?)<!--\se.*?onlineuser\s-->/', function ($match) use (&$replace) {
		return array_shift($replace);
	}, $boardstats);
}

function trinket_teamavatar()
{
	global $user;
	$user = array_merge($user, trinket_fetch_user($user['uid'], "", 'teamuser'));
}

function trinket_forumbits(&$forum)
{
	if ($forum['lastposteruid']) {
		$lastposter = trinket_fetch_user($forum['lastposteruid']);
		$forum['lastposter'] = $lastposter['profilelink_formatted'];
		$forum['lastposter_avatar'] = $lastposter['avatar'];
	}
}

function trinket_bits()
{
	$fields = ['uid' => 'profilelink'];

	if (THIS_SCRIPT == 'search.php') {
		global $search;
		if ($search['resulttype'] == 'threads') {
			$global = 'thread';
			global $lastposterlink, $lastposteravatar;
			$fields['lastposteruid'] = 'lastposter';
		} else {
			$global = 'post';
		}
	} else if (THIS_SCRIPT == 'forumdisplay.php') {
		$global = 'thread';
		$fields['lastposteruid'] = 'lastposter';
	}
	global $$global;

	foreach ($fields as $key => $value) {
		$user = trinket_fetch_user($$global[$key]);
		$$global[$value] = $user['profilelink_formatted'];
		$$global[$value . '_avatar'] = $user['avatar'];
	}

	if (THIS_SCRIPT == 'search.php' && $search['resulttype'] == 'threads') {
		$lastposterlink = $$global['lastposter'];
		$lastposteravatar = $$global['lastposter_avatar'];
	}
}

function trinket_inline($id, $name = false, $class = [])
{
	if (!is_array($class)) $class = explode(',', $class);
	$class[] = 'inline';
	$user = trinket_fetch_user($id, $name, $class);
	return preg_replace('/<a(.*?)>/', "<a class='trinket-inline'$1>{$user['avatar_html']}", $user['profilelink_formatted'], 1);
}

function trinket_purgecache($uid = 0)
{
	global $mybb, $cache;

	if ($uid < 0) {
		$cache->delete('trinket');
	} else {
		if (!$uid) {
			if ((defined('IN_ADMINCP') && ($mybb->input['remove_avatar'] || $_FILES['avatar_upload']['name']))
				|| (THIS_SCRIPT == 'modcp.php' && !empty($mybb->input['remove_avatar']))
			) { // Admin / Moderator action
				$uid = $mybb->get_input('uid', MyBB::INPUT_INT);
			} else if (isset($mybb->user['uid'])) { // Self action by user
				$uid = (int)$mybb->user['uid'];
			}
		}

		if ($uid) {
			$users = $cache->read('trinket');
			if (isset($users[$uid])) {
				unset($users[$uid]);
				$cache->update('trinket', $users);
			}
		}
	}
}

function trinket_profilepop()
{
	global $mybb;
	$mybb->settings['trinket_profilepop'] = true; // SETTING
	if ($mybb->settings['trinket_profilepop'] && $mybb->input['action'] == 'profilepop') {
		$uid = $mybb->get_input('uid', MyBB::INPUT_INT);
		if ($uid) {
			$addl_fields = ['threadnum', 'postnum'];
			if ($mybb->settings['enablereputation']) $addl_fields[] = 'reputation';
			$user = trinket_fetch_user($uid, false, [], $addl_fields, false); // Disable cache. Will use js localstore
		}
	}
}