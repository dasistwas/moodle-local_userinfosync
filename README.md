local_userinfosync
==================

Local Moodle plugin for synchronizing custom user info fields between Moodle instances in a MNet network
In order to make the syncing work create user profile fields on identity provider and identitiy receiver with the identical names
The profile fields are synced via cron, that executes all 10 minutes.
All user who logged in to the site since the last execution of the cronjob are synced via the cronjob
If you do not want to use cron for syncing change $plugin->cron	   = 600; to $plugin->cron = 0; in version.php
From another plugin you can call userinfosync::update_user_fields($userids) to initiate profile syncing
$userids is either a single user id as int, or an array of user ids.

