<?php

Plugin::triggerEvent('mobile_my_videos.start');
Functions::redirectIf((boolean) Settings::get('mobile_site'), HOST . '/');

// Verify if user is logged in
$userService = new UserService();
$this->view->vars->loggedInUser = $userService->loginCheck();

// Establish page variables, objects, arrays, etc
$videoMapper = new VideoMapper();
$db = Registry::get('db');

// Retrieve video count
$query = "SELECT COUNT(video_id) FROM " . DB_PREFIX . "videos WHERE status = 'approved' AND private = '0' AND gated = '0'";
$db->fetchRow($query);
$this->view->vars->count = $db->rowCount();

// Retrieve video list
$query = "SELECT video_id FROM " . DB_PREFIX . "videos WHERE status = 'approved' AND private = '0' AND gated = '0' ORDER BY video_id DESC LIMIT 20";
$this->view->vars->videos = array();
$videoResults = $db->fetchAll($query);
$this->view->vars->videos = $videoMapper->getVideosFromList(
    Functions::arrayColumn($videoResults, 'video_id')
);

Plugin::triggerEvent('mobile_my_videos.end');