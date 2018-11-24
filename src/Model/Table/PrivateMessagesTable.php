<?php
/**
 * Tatoeba Project, free collaborative creation of multilingual corpuses project
 * Copyright (C) 2009 DEPARIS Étienne <etienne.deparis@umaneti.net>
 * Copyright (C) 2010 SIMON   Allan   <allan.simon@supinfo.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace App\Model\Table;

use Cake\Database\Schema\TableSchema;
use Cake\ORM\Table;
use App\Model\CurrentUser;
use Cake\I18n\Time;
use Cake\Event\Event;
use Cake\Validation\Validator;
use App\Event\NotificationListener;


class PrivateMessagesTable extends Table
{
    public $name = 'PrivateMessage';

    protected function _initializeSchema(TableSchema $schema)
    {
        $schema->setColumnType('date', 'string');
        return $schema;
    }

    public function initialize(array $config)
    {
        $this->belongsTo('Users');
        $this->belongsTo('Recipients', [
            'className' => 'Users',
            'foreignKey' => 'recpt'
        ]);
        $this->belongsTo('Authors', [
            'className' => 'Users',
            'foreignKey' => 'sender'
        ]);

        $this->getEventManager()->on(new NotificationListener());
    }

    public function validationDefault(Validator $validator)
    {
        $validator
            ->requirePresence('content')
            ->add('content', 'notBlank', [
                'rule' => function($value, $provider) {
                    $data = $provider['data'];
                    if (isset($data['folder']) && $data['folder'] == 'Drafts') {
                        return true;
                    } else {
                        return !empty($value);
                    }
                },
                'message' =>  __('You must fill at least the content field.')
            ]);

        $validator
            ->add('recpt', 'notBlank', [
                'rule' => 'notBlank',
                'message' => __('You must fill at least the "To" field and the content field.')
            ]);
        
        return $validator;
    }

    /**
     * Get private messages by folder.
     *
     * @param string $folder Name of the folder we want the messages.
     * @param int    $userId Id of the user.
     *
     * @return array
     */
    public function getMessages($folder, $userId)
    {
        return $this->find(
            'all',
            array(
                'conditions' => array(
                    'PrivateMessage.user_id' => $userId,
                    'PrivateMessage.folder' => $folder
                ),
                'order' => 'PrivateMessage.date DESC',
                'contain' => array(
                    'Sender' => array(
                        'fields' => array('username', 'image'),
                    ),
                    'Recipient' => array(
                        'fields' => array('username', 'image')
                    )
                )
            )
        );
    }

    /**
     * Return query for paginated messages in specified folder.
     *
     * @param  int $userId    ID for current user.
     * @param  string $folder Folder to get messages for.
     * @param  string $status Type of messages to get: 'read' or 'unread'
     *
     * @return array
     */
    public function getPaginatedMessages($userId, $folder, $status)
    {
        $conditions = array('folder' => $folder);

        if ($folder == 'Inbox') {
            $conditions['recpt'] = $userId;
        } else if ($folder == 'Sent' || $folder == 'Drafts') {
            $conditions['sender'] = $userId;
        } else if ($folder == 'Trash') {
            $conditions['user_id'] = $userId;
        }

        if ($status == 'read') {
            $conditions['isnonread'] = 0;
        } else if ($status == 'unread') {
            $conditions['isnonread'] = 1;
        }

        return [
            'conditions' => $conditions,
            'contain' => [
                'Authors' => [
                    'fields' => [
                        'id',
                        'username',
                        'image'
                    ]
                ],
                'Recipients' => [
                    'fields' => [
                        'id',
                        'username',
                        'image',
                    ]
                ]
            ],
            'order' => ['date' => 'DESC'],
            'limit' => 20
        ];
    }

    /**
     * Get message by id.
     *
     * @param int $messageId ID of the message to retrieve.
     *
     * @return array
     */
    public function getMessageWithId($messageId)
    {
        return $this->find(
            'first',
            array(
                'conditions' => array('PrivateMessage.id' => $messageId),
                'contain' => array(
                    'Sender' => array(
                        'fields' => array('username', 'image')
                    )
                )
            )
        );
    }

    /**
     * Get unread message count for user.
     *
     * @param int $userId ID for user.
     *
     * @return int
     */
    public function numberOfUnreadMessages($userId)
    {
        return $this->find()
            ->where([
                'recpt' => $userId,
                'folder' => 'Inbox',
                'isnonread' => 1
            ])
            ->count();
    }

    /**
     * Return count of messages sent by user in the last 24 hours.
     *
     * @param  int $userId ID for user.
     *
     * @return int
     */
    public function todaysMessageCount($userId)
    {
        $yesterday = new Time('-24 hours');

        return $this->find()
            ->where([
                'sender' => $userId,
                'folder IN' => ['Sent', 'Trash'],
                'date >=' => $yesterday->i18nFormat('yyyy-MM-dd HH:mm:ss')
            ])
            ->count();
    }

    /**
     * Build message to send.
     *
     * @param  array  $data          Private message data.
     * @param  int    $currentUserId ID of current user.
     * @param  string $now           Current timestamp.
     *
     * @return array
     */
    private function buildMessage($data, $currentUserId, $now)
    {
        $message = array(
            'sender'    => $currentUserId,
            'date'      => $now,
            'folder'    => 'Inbox',
            'title'     => $data['PrivateMessage']['title'],
            'content'   => $data['PrivateMessage']['content'],
            'isnonread' => 1,
        );

        if ($data['PrivateMessage']['messageId']) {
            $message['id'] = $data['PrivateMessage']['messageId'];
        }

        return $message;
    }

    /**
     * Save a draft message.
     *
     * @param  int      $currentUserId ID for current user.
     * @param  string   $now           Timestamp.
     * @param  array    $data          Form data from controller.
     *
     * @return array                   Draft.
     */
    public function saveDraft($currentUserId, $now, $data)
    {
        $draft = array(
            'user_id'       => $currentUserId,
            'sender'        => $currentUserId,
            'draft_recpts'  => $data['recipients'],
            'date'          => $now,
            'folder'        => 'Drafts',
            'title'         => $data['title'],
            'content'       => $data['content'],
            'isnonread'     => 1,
            'sent'          => 0,
        );

        if ($data['messageId']) {
            $draft['id'] = $data['messageId'];
        }

        $entity = $this->newEntity($draft);
        return $this->save($entity);
    }

    /**
     * Save message to recipients inbox.
     *
     * @param  array $message Message to send.
     * @param  int   $recptId User id for recipient.
     *
     * @return array
     */
    private function saveToInbox($message, $recptId)
    {
        $message = array_merge($message, array(
            'recpt' => $recptId,
            'user_id' => $recptId,
            'draft_recpts' => '',
            'sent' => 1
        ));
        $message = $this->newEntity($message);
        return $this->save($message);
    }

    /**
     * Save message to senders outbox.
     *
     * @param  array $messageToSave Message to save to outbox.
     * @param  int   $recptId       User id for recipient.
     * @param  int   $currentUserId User id for current user.
     *
     * @return array
     */
    private function saveToOutbox($messageToSave, $recptId, $currentUserId)
    {
        $message = array_merge($messageToSave, array(
            'user_id'   => $currentUserId,
            'folder'    => 'Sent',
            'isnonread' => 0,
            'recpt' => $recptId,
            'draft_recpts' => '',
            'sent' => 1,
            'id' => null
        ));

        $message = $this->newEntity($message);
        return $this->save($message);;
    }

    public function notify($recptId, $now, $message)
    {
        $toSend = $this->buildMessage($message, 0, $now);
        return $this->saveToInbox($toSend, $recptId);
    }

    public function send($currentUserId, $now, $message)
    {
        $toSend = $this->buildMessage(
            $message,
            $currentUserId,
            $now
        );

        $recipients = $this->_buildRecipientsArray($message['PrivateMessage']['recpt']);

        $sentToday = $this->todaysMessageCount($currentUserId);

        foreach ($recipients as $recpt) {
            if (!$this->canSendMessage($sentToday)) {
                $this->validationErrors['limitExceeded'] = array(
                    format(
                        __("You have reached your message limit for today. ".
                           "Please wait until you can send more messages. ".
                           "If you have received this message in error, ".
                           "please contact administrators at {email}."),
                        array('email' => 'team@tatoeba.org')
                    ),
                );
                return false;
            }

            $recptId = $this->Users->getIdFromUsername($recpt);

            if (!$recptId) {
                $this->validationErrors['recpt'] = array(
                    format(
                        __('The user {username} to whom you want to send this '.
                           'message does not exist. Please try with another username.'),
                        array('username' => $recpt)
                    ),
                );
                return false;
            }

            $message = $this->saveToInbox($toSend, $recptId);
            if (!$message) {
                return false;
            } else {
                $event = new Event('Model.PrivateMessage.messageSent', $this, array(
                    'message' => $message,
                ));
                $this->getEventManager()->dispatch($event);
            }

            $this->id = null;

            $this->saveToOutbox(
                $toSend,
                $recptId,
                $currentUserId
            );

            $this->id = null;

            $sentToday += 1;
        }
        return true;
    }

    /**
     * Build array of unique recipients from recipents string.
     *
     * @param  array $recpt Recipents
     * @return array
     */
    private function _buildRecipientsArray($recpt)
    {
        $recptArray = explode(',', $recpt);
        $recptArray = array_map('trim', $recptArray);
        $recptArray = array_filter($recptArray);

        return array_unique($recptArray, SORT_REGULAR);
    }

    /**
     * Return true if user can send message. New users can only send 5/24 hours.
     *
     * @param  int  $messagesToday Number of messages sent today.
     *
     * @return bool
     */
    public function canSendMessage($messagesToday)
    {
        if (CurrentUser::isNewUser()) {
            return $messagesToday < 5;
        }

        return true;
    }

    /**
     * Mark a private message as read.
     *
     * @param  array $message Private message.
     *
     * @return array
     */
    public function markAsRead($message)
    {
        if ($message['PrivateMessage']['isnonread'] == 1) {
            $message = $this->toggleUnread($message);
        }

        return $message;
    }

    /**
     * Toggle message isnonread column.
     *
     * @param  array $message Private message.
     *
     * @return array
     */
    public function toggleUnread($message)
    {
        $status = !! $message['PrivateMessage']['isnonread'];

        $message['PrivateMessage']['isnonread'] = !$status;

        $this->save($message);

        return $message;
    }
}
