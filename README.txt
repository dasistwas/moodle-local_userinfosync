local_userinfosync
==================

Local Moodle plugin for synchronizing custom user info fields between Moodle instances in an MNet network
How to get syncing going: create user profile fields on the identity provider and identitiy receiver with identical names
The profile fields are synced everytime cron is executed
All users who were logged in to the site after the last execution of the cronjob are synced via the cronjob
If you do not want to use cron for syncing change version.php from $plugin->cron = 1; to $plugin->cron = 0;
Call userinfosync::update_user_fields($userids) to initiate profile syncing from a plugin
$userids is either a single user id as int, or an array of user ids.

