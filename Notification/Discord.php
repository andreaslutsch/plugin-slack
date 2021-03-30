<?php

namespace Kanboard\Plugin\Discord\Notification;

use CommentModelTest;
use DiscordSDK;
use Kanboard\Core\Base;
use Kanboard\Core\Notification\NotificationInterface;
use Kanboard\Model\TaskModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\CommentModel;
use Kanboard\Model\TaskFileModel;
use ReflectionClass;
use ReflectionException;


require_once '../php-discord-sdk/support/sdk_discord.php';


// Helper functions

function clean($string)
{
    $string = str_replace(' ', '-', $string); // Replaces all spaces with hyphens.
    return preg_replace('/[^A-Za-z0-9\-.]/', '', $string); // Removes special chars.
}

// Overloaded classes 
/**
 * Discord Notification
 *
 * @package  notification
 * @author   Andrej Zlámala
 * @author   Andreas
 */

class Discord extends Base implements NotificationInterface
{

    /**
     * @param $projectId
     * @return array
     */
    private function getProjectEventValues($projectId)
    {
        $constants = array();
        try {
            $reflection = new ReflectionClass(TaskModel::class);
            $constants = array_values($reflection->getConstants());
            $reflection = new ReflectionClass(SubtaskModel::class);
            $constants = array_merge($constants, array_values($reflection->getConstants()));
            $reflection = new ReflectionClass(CommentModel::class);
            $constants = array_merge($constants, array_values($reflection->getConstants()));
            $reflection = new ReflectionClass(TaskFileModel::class);
            $constants = array_merge($constants, array_values($reflection->getConstants()));
            $constants = array_filter($constants, 'is_string');
        } catch (ReflectionException $exception) {
            return array();
        } finally {
            $events = array();
        }

        foreach ($constants as $key => $value) {
            $id = str_replace(".", "_", $value);

            $event_value = $this->projectMetadataModel->get($projectId, "Discord_" . $id, $this->configModel->get("Discord_" . $id));

            if ($event_value == 1) {
                array_push($events, $value);
            }
        }

        return $events;
    }

    /**
     * @param $userId
     * @return array
     */
    private function getUserEventValues($userId)
    {
        $constants = array();
        try {
            $reflection = new ReflectionClass(TaskModel::class);
            $constants = $reflection->getConstants();
        } catch (ReflectionException $exception) {
            return array();
        } finally {
            $events = array();
        }

        foreach ($constants as $key => $value) {
            if (strpos($key, 'EVENT') !== false) {
                $id = str_replace(".", "_", $value);

                $event_value = $this->userMetadataModel->get($userId, $id, $this->configModel->get($id));
                if ($event_value == 1) {
                    array_push($events, $value);
                }
            }
        }

        return $events;
    }

    /**
     * Send notification to a user
     *
     * @access public
     * @param  array     $user
     * @param  string    $eventName
     * @param  array     $eventData
     */
    public function notifyUser(array $user, $eventName, array $eventData)
    {
        $webhook = $this->userMetadataModel->get($user['id'], 'discord_webhook_url', $this->configModel->get('discord_webhook_url'));

        if (!empty($webhook)) {
            $events = $this->getUserEventValues($user['id']);

            foreach ($events as $event) {
                if ($eventName == $event) {
                    if ($eventName === TaskModel::EVENT_OVERDUE) {
                        foreach ($eventData['tasks'] as $task) {
                            $project = $this->projectModel->getById($task['project_id']);
                            $eventData['task'] = $task;
                            $this->sendMessage($webhook, $project, $eventName, $eventData);
                        }
                    } else {
                        $project = $this->projectModel->getById($eventData['task']['project_id']);
                        $this->sendMessage($webhook, $project, $eventName, $eventData);
                    }
                }
            }
        }
    }

    /**
     * Send notification to a project
     *
     * @access public
     * @param  array     $project
     * @param  string    $eventName
     * @param  array     $eventData
     */
    public function notifyProject(array $project, $eventName, array $eventData)
    {
        $webhook = $this->projectMetadataModel->get($project['id'], 'discord_webhook_url', $this->configModel->get('discord_webhook_url'));

        if (!empty($webhook)) {
            $events = $this->getProjectEventValues($project['id']);
            foreach ($events as $event) {
                if ($eventName == $event) {
                    $this->sendMessage($webhook, $project, $eventName, $eventData);
                }
            }
        }
    }

    /**
     * Get message to send
     *
     * @access public
     * @param  array     $project
     * @param  string    $eventName
     * @param  array     $eventData
     * @return array
     */
    public function getMessage(array $project, $eventName, array $eventData)
    {
        $fileinfo = array(
            "avatar" => array(),
            "thumbnail" => array(),
        );


        // Get user information if logged in
        if ($this->userSession->isLogged()) {
            $user = $this->userSession->getAll();
            $author = $this->helper->user->getFullname();
            $title = $this->notificationModel->getTitleWithAuthor($author, $eventName, $eventData);
            $avatar_path = getcwd() . '/data/files/' . $user['avatar_path'];
            $avatar_file = array(
                "name" => "file",
                "filename" => 'avatar.png',
                "type" => "image/png",
                "data" => file_get_contents($avatar_path),
            );
            $fileinfo["avatar"] = $avatar_file;
        } else {
            $title = $this->notificationModel->getTitleWithoutAuthor($eventName, $eventData);
        }

        $task_name = '**' . $eventData['task']['title'] . '**';
        $title = "📝" . str_replace('the task', $task_name, $title);

        $message = str_replace($task_name, '[' . $task_name . '](' . $this->helper->url->to('TaskViewController', 'show', array('task_id' => $eventData['task']['id'], 'project_id' => $project['id']), '', true) . ')', $title);

        $description_events = array(TaskModel::EVENT_CREATE, TaskModel::EVENT_UPDATE, TaskModel::EVENT_USER_MENTION);
        $subtask_events = array(SubtaskModel::EVENT_CREATE, SubtaskModel::EVENT_UPDATE, SubtaskModel::EVENT_DELETE);
        $comment_events = array(CommentModel::EVENT_UPDATE, CommentModel::EVENT_CREATE, CommentModel::EVENT_DELETE, CommentModel::EVENT_USER_MENTION);

        if (in_array($eventName, $subtask_events))  // For subtask events
        {
            $subtask_status = $eventData['subtask']['status'];
            $subtask_symbol = '';

            if ($subtask_status == SubtaskModel::STATUS_DONE) {
                $subtask_symbol = '❌ ';
            } elseif ($subtask_status == SubtaskModel::STATUS_TODO) {
                $subtask_symbol = '';
            } elseif ($subtask_status == SubtaskModel::STATUS_INPROGRESS) {
                $subtask_symbol = '🕘 ';
            }

            $message .= "\n  ↳ " . $subtask_symbol . $eventData['subtask']['title'];
        } elseif (in_array($eventName, $description_events))  // If description available
        {
            if ($eventData['task']['description'] != '') {
                $message .= "\n✏️ " . $eventData['task']['description'];
            }
        } elseif (in_array($eventName, $comment_events))  // If comment available
        {
            $message .= "\n💬 " . $eventData['comment']['comment'];
        } elseif ($eventName === TaskFileModel::EVENT_CREATE)  // If attachment available
        {
            $file_path = getcwd() . "/data/files/" . $eventData['file']['path'];
            $file_name = $eventData['file']['name'];
            $is_image = $eventData['file']['is_image'];

            if ($is_image == true) {
                $thumbnail_file = array(
                    "name" => "file2",
                    "filename" => "thumbnail.png",
                    "type" => "image/png",
                    "data" => file_get_contents($file_path),
                );

                $fileinfo["thumbnail"] = $thumbnail_file;
            }
        }

        // Create embed object

        $embedTitle = isset($eventData['project_name']) ? '**[' . $eventData['project_name'] . ']** ' : '**[' . $eventData['task']['project_name'] . ']** ';
        $embedType = 'rich';
        $embedDescription = $message;
        $embedTimestamp = date("c", strtotime("now"));
        $embedColor = hexdec('f9df18');
        $embedFooter = [
            'text' => $author,
            'icon_url' => 'attachment://avatar.png',
        ];
        $embedThumbnail = ['url' => 'attachment://thumbnail.png'];
        $embedAuthor = [
            'name' => $author,
            #'url' => 'https://kanboard.org',
            'icon_url' => 'attachment://avatar.png',
        ];

        $embed = array(array(
            'title' => $embedTitle,
            'type' => $embedType,
            'description' => $embedDescription,
            'timestamp' => $embedTimestamp,
            'color' => $embedColor,
            #'footer' => $embedFooter,
            'thumbnail' => $embedThumbnail,
            'author' => $embedAuthor,
            // 'fields' => [
            //     [
            //     "name" => "value",
            //     "value" => "value",
            //     "inline" => false,
            //     ],
            // ] ,
        ));

        $payload = [
            'username' => 'Kanboard',
            'avatar_url' => 'https://raw.githubusercontent.com/kanboard/kanboard/master/assets/img/favicon.png',
            'embeds' => $embed,
        ];

        $data = [
            "options" => $payload,
            "fileinfo" => $fileinfo,
        ];

        return $data;
    }

    /**
     * Send message to Discord
     *
     * @access protected
     * @param  string    $webhook
     * @param  array     $project
     * @param  string    $eventName
     * @param  array     $eventData
     */
    protected function sendMessage($webhook, array $project, $eventName, array $eventData)
    {
        $payload = $this->getMessage($project, $eventName, $eventData);
        DiscordSDK::SendWebhookMessage($webhook, $payload["options"], $payload["fileinfo"]);
    }
}