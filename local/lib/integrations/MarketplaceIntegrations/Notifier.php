<?php

namespace MarketplaceIntegration;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Event;
use Local\Init\ServiceHandler;

/**
 * Class for working with integrations notifications
 */
class Notifier
{
    public const DEFAULT_EMAIL = 'email-address@domain.tld';
    public const NOTIFICATION_EVENT_NAME = 'INTEGRATIONS_NOTIFICATIONS';
    public const NOTIFICATION_EVENT_ID = 100;

    /** @var string Event code */
    private string $code;
    /** @var string Event type */
    private string $type;
    /** @var string Path to log */
    private string $logFilePath;
    /** @var string Message to write */
    private string $message;
    /** @var string|null Email subject */
    private ?string $subject;
    /** @var string List of emails */
    private string $mailList;
    /** @var string Additional info to log and email */
    private string $additionalInfo;


    /**
     * @param string $code Event code
     * @param string $type Event type ['error', 'info']
     * @param string $logFilePath Log file path
     * @param string $message Message to log
     * @param string $subject Email subject
     * @param string $mailList Email list
     */
    public function __construct(string $code, string $type, string $logFilePath, string $message, string $subject, string $mailList)
    {
        $this->code = $code ?? '-';
        $this->type = $type ?? 'info';
        $this->logFilePath = $logFilePath ?? '';
        $this->message = $message ?? '';
        $this->subject = $subject ?? Loc::getMessage('HI_N_DEFAULT_SUBJECT');
        $this->mailList = $mailList ?? self::DEFAULT_EMAIL;
        $this->additionalInfo = Loc::getMessage('HI_N_ADDITIONAL_INFO', array(
            '#TYPE#' => $this->type, '#CODE#' => $this->code
        ));
    }


    /**
     * Method adds data to log
     */
    public function addToLog(): void
    {
        ServiceHandler::writeToLog($this->message, $this->logFilePath, $this->additionalInfo);
    }


    /**
     * Method sends email
     */
    public function sendEmailMessage(): void
    {
        Event::send(array(
            'EVENT_NAME' => self::NOTIFICATION_EVENT_NAME,
            'MESSAGE_ID' => self::NOTIFICATION_EVENT_ID,
            'LID' => 's1',
            'C_FIELDS' => array(
                'SUBJECT' => $this->subject,
                'MAIL_LIST' => $this->mailList,
                'MESSAGE' => nl2br($this->message) . "<br><br><i>" . $this->additionalInfo . "</i>",
            )
        ));
    }
}