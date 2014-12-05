<?php

class VideoService extends ServiceAbstract
{
    /**
     * Retrieve the URL to a given video's play page
     * @param Video $video Instance of video to generate URL for
     * @return string Returns full URL to video's play page
     */
    public function getUrl(Video $video)
    {
        return HOST . '/videos/' . $video->videoId . '/' . Functions::createSlug($video->title);
    } 
    
    /**
     * Delete a video
     * @param Video $video Instance of video to be deleted
     * @return void Video is deleted from database and all related files and records are also deleted
     */
    public function delete(Video $video)
    {
        // Delete files
        try {
            Filesystem::delete(UPLOAD_PATH . '/h264/' . $video->filename . '.mp4');
            Filesystem::delete(UPLOAD_PATH . '/theora/' . $video->filename . '.ogv');
            if (file_exists(UPLOAD_PATH . '/vp8/' . $video->filename . '.webm')) Filesystem::delete(UPLOAD_PATH . '/vp8/' . $video->filename . '.webm');
            Filesystem::delete(UPLOAD_PATH . '/thumbs/' . $video->filename . '.jpg');
            Filesystem::delete(UPLOAD_PATH . '/mobile/' . $video->filename . '.mp4');
        } catch (Exception $e) {
            App::Alert('Error During Video Removal', "Unable to delete video files for: $video->filename. The video has been removed from the system, but the files still remain. Error: " . $e->getMessage());
        }

        // Delete comments on video
        $commentService = new CommentService();
        $commentMapper = new CommentMapper();
        $comments = $commentMapper->getMultipleCommentsByCustom(array('video_id' => $video->videoId));
        foreach ($comments as $comment) $commentService->delete($comment);
        
        // Delete video's ratings
        $ratingService = new RatingService();
        $ratingMapper = new RatingMapper();
        $ratings = $ratingMapper->getMultipleRatingsByCustom(array('video_id' => $video->videoId));
        foreach ($ratings as $rating) $ratingService->delete($rating);
        
        // Delete playlist entries for video
        $playlistService = new PlaylistService();
        $playlistEntryMapper = new PlaylistEntryMapper();
        $playlistMapper = new PlaylistMapper();
        $playlistEntries = $playlistEntryMapper->getMultiplePlaylistEntriesByCustom(array(
            'video_id' => $video->videoId
        ));
        foreach ($playlistEntries as $playlistEntry) {
            $playlist = $playlistMapper->getPlaylistById($playlistEntry->playlistId);
            $playlistService->deleteVideo($video, $playlist);
        }
        
        // Delete video flags
        $flagService = new FlagService();
        $flagMapper = new FlagMapper();
        $flags = $flagMapper->getMultipleFlagsByCustom(array('object_id' => $video->videoId, 'type' => 'video'));
        foreach ($flags as $flag) $flagService->delete($flag);

        // Delete video
        $videoMapper = $this->_getMapper();
        $videoMapper->delete($video->videoId);
    }

    /**
     * Generate a unique random string for a video filename
     * @return string Random video filename
     */
    public function generateFilename()
    {
        $videoMapper = new VideoMapper();
        $filenameAvailable = null;
        do {
            $filename = Functions::random(20);
            if (!$videoMapper->getVideoByCustom(array('filename' => $filename))) $filenameAvailable = true;
        } while (empty($filenameAvailable));
        return $filename;
    }

    /**
     * Generate a unique random url for accessing a private video
     * @return string URL for private video is returned
     */
    public function generatePrivate()
    {
        $videoMapper = new VideoMapper();
        $privateAvailable = null;
        do {
            $private = Functions::random(7);
            if (!$videoMapper->getVideoByCustom(array('private_url' => $private))) $privateAvailable = true;
        } while (empty($privateAvailable));
        return $private;
    }

    /**
     * Make a video visible to the public and notify subscribers of new video
     * @param Video $video Instance of the video being updated
     * @param string $action Step in the approval proccess to perform. Allowed values: create|activate|approve
     * @return void Video is activated, subscribers are notified, and admin
     * alerted. If approval is required video is marked as pending and placed in queue
     */
    public function approve(Video $video, $action)
    {
        $send_alert = false;
        $videoMapper = $this->_getMapper();

        // 1) Video completed encoding
        // 2) Video is being approved by admin for first time
        if ($action == 'activate' || ($action == 'approve' && !$video->released)) {

            // User uploaded video but needs admin approval
            if ($action == 'activate' && Settings::Get ('auto_approve_videos') == '0') {
                
                // Send Admin Approval Alert
                $send_alert = true;
                $subject = 'New Video Awaiting Approval';
                $body = 'A new video has been uploaded and is awaiting admin approval.';

                // Set Pending
                $video->status = VideoMapper::PENDING_APPROVAL;
                $videoMapper->save($video);
            } else {

                // Send Admin Alert
                if ($action == 'activate' && Settings::Get ('alerts_videos') == '1') {
                    $send_alert = true;
                    $subject = 'New Video Uploaded';
                    $body = 'A new video has been uploaded.';
                }

                // Activate & Release
                $video->status = 'approved';
                $video->released = true;
                $videoMapper->save($video);
                
                // Send video owner notification if opted-in
                $this->_notifyUserVideoIsReady($video);

                // Send subscribers notification of new video
                $this->_notifySubscribersOfNewVideo($video);
            }

        // Video is being re-approved
        } else if ($action == 'approve' && $video->released) {
            // Approve Video
            $video->status = 'approved';
            $videoMapper->save($video);
        }

        // Send admin alert
        if ($send_alert) {
            $body .= "\n\n=======================================================\n";
            $body .= "Title: $video->title\n";
            $body .= "URL: " . $this->getUrl($video) ."\n";
            $body .= "=======================================================";
            App::Alert ($subject, $body);
        }
    }
    
    protected function _notifySubscribersOfNewVideo(Video $video)
    {
        $config = Registry::get('config');
        $userService = new UserService();
        $privacyService = new PrivacyService();
        $user = $this->_getVideoUser($video);
        $subscriberList = $userService->getSubscribedUsers($user);
        foreach ($subscriberList as $subscriber) {
            if ($privacyService->optCheck($subscriber, Privacy::NEW_VIDEO)) {
                $replacements = array (
                    'host'      => HOST,
                    'sitename'  => $config->sitename,
                    'email'     => $subscriber->email,
                    'member'    => $subscriber->username,
                    'title'     => $video->title,
                    'video_url' => $this->getUrl($video)
                );
                $mail = new Mail();
                $mail->LoadTemplate ('new_video', $replacements);
                $mail->Send ($subscriber->email);
            }
        }
    }
    
    /**
     * Send video owner notification that video is now available 
     */
    protected function _notifyUserVideoIsReady(Video $video)
    {
        $config = Registry::get('config');
        $user = $this->_getVideoUser($video);
        $privacyService = new PrivacyService();
        if ($privacyService->optCheck($user, Privacy::VIDEO_READY)) {
            $replacements = array(
                'host'      => HOST,
                'sitename'  => $config->sitename,
                'email'     => $user->email,
                'title'     => $video->title,
                'video_url' => $this->getUrl($video)
            );
            $mail = new Mail();
            $mail->LoadTemplate('video_ready', $replacements);
            $mail->Send($user->email);
        }
    }
    
    /**
     * Retrieve instance of Video mapper
     * @return VideoMapper Mapper is returned
     */
    protected function _getMapper()
    {
        return new VideoMapper();
    }
    
    /**
     * Retrieve user who owns given video
     * @param Video $video
     * @return User Returns instance of user 
     */
    protected function _getVideoUser(Video $video)
    {
        $userMapper = new UserMapper();
        return $userMapper->getUserById($video->userId);
    }
}