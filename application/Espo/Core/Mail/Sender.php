<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2015 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 ************************************************************************/

namespace Espo\Core\Mail;

use \Espo\Entities\Email;

use \Zend\Mime\Message as MimeMessage;
use \Zend\Mime\Part as MimePart;
use \Zend\Mime\Mime as Mime;

use \Zend\Mail\Message;
use \Zend\Mail\Transport\Smtp as SmtpTransport;
use \Zend\Mail\Transport\SmtpOptions;

use \Espo\Core\Exceptions\Error;

class Sender
{
    protected $config;

    protected $transport;

    protected $isGlobal = false;

    protected $params = array();

    public function __construct($config)
    {
        $this->config = $config;
        $this->useGlobal();
    }

    public function resetParams()
    {
        $this->params = array();
        return $this;
    }

    public function setParams(array $params = array())
    {
        $this->params = array_merge($this->params, $params);
        return $this;
    }

    public function useSmtp(array $params = array())
    {
        $this->isGlobal = false;
        $this->params = $params;

        $this->transport = new SmtpTransport();

        $opts = array(
            'name' => 'admin',
            'host' => $params['server'],
            'port' => $params['port'],
            'connection_config' => array()
        );
        if ($params['auth']) {
            $opts['connection_class'] = 'login';
            $opts['connection_config']['username'] = $params['username'];
            $opts['connection_config']['password'] = $params['password'];
        }
        if ($params['security']) {
            $opts['connection_config']['ssl'] = strtolower($params['security']);
        }

        if (in_array('fromName', $params)) {
            $this->params['fromName'] = $params['fromName'];
        }
        if (in_array('fromAddress', $params)) {
            $this->params['fromAddress'] = $params['fromAddress'];
        }

        $options = new SmtpOptions($opts);
        $this->transport->setOptions($options);

        return $this;
    }

    public function useGlobal()
    {
        $this->params = array();
        if ($this->isGlobal) {
            return $this;
        }

        $this->transport = new SmtpTransport();

        $config = $this->config;

        $opts = array(
            'name' => 'admin',
            'host' => $config->get('smtpServer'),
            'port' => $config->get('smtpPort'),
            'connection_config' => array()
        );
        if ($config->get('smtpAuth')) {
            $opts['connection_class'] = 'login';
            $opts['connection_config']['username'] = $config->get('smtpUsername');
            $opts['connection_config']['password'] = $config->get('smtpPassword');
        }
        if ($config->get('smtpSecurity')) {
            $opts['connection_config']['ssl'] = strtolower($config->get('smtpSecurity'));
        }

        $options = new SmtpOptions($opts);
        $this->transport->setOptions($options);

        $this->isGlobal = true;

        return $this;
    }

    public function send(Email $email, $params = array())
    {
        $message = new Message();
        $config = $this->config;
        $params = $this->params + $params;

        if ($email->get('from')) {
            $fromName = null;
            if (!empty($params['fromName'])) {
                $fromName = $params['fromName'];
            } else {
                $fromName = $config->get('outboundEmailFromName');
            }
            $message->addFrom(trim($email->get('from')), $fromName);
        } else {
            if (!empty($params['fromAddress'])) {
                $fromAddress = $params['fromAddress'];
            } else {
                if (!$config->get('outboundEmailFromAddress')) {
                    throw new Error('outboundEmailFromAddress is not specified in config.');
                }
                $fromAddress = $config->get('outboundEmailFromAddress');
            }

            if (!empty($params['fromName'])) {
                $fromName = $params['fromName'];
            } else {
                $fromName = $config->get('outboundEmailFromName');
            }

            $message->addFrom($fromAddress, $fromName);
        }

        if (!empty($params['replyToAddress'])) {
            $replyToName = null;
            if (!empty($params['replyToName'])) {
                $replyToName = $params['replyToName'];
            }
            $message->setReplyTo($params['replyToAddress'], $replyToName);
        }

        $value = $email->get('to');
        if ($value) {
            $arr = explode(';', $value);
            if (is_array($arr)) {
                foreach ($arr as $address) {
                    $message->addTo(trim($address));
                }
            }
        }

        $value = $email->get('cc');
        if ($value) {
            $arr = explode(';', $value);
            if (is_array($arr)) {
                foreach ($arr as $address) {
                    $message->addCC(trim($address));
                }
            }
        }

        $value = $email->get('bcc');
        if ($value) {
            $arr = explode(';', $value);
            if (is_array($arr)) {
                foreach ($arr as $address) {
                    $message->addBCC(trim($address));
                }
            }
        }

        $attachmentPartList = array();
        $attachmentCollection = $email->get('attachments');
        $attachmentInlineCollection = $email->getInlineAttachments();

        if (!empty($attachmentCollection)) {
            foreach ($attachmentCollection as $a) {
                $fileName = 'data/upload/' . $a->id;
                $attachment = new MimePart(file_get_contents($fileName));
                $attachment->disposition = Mime::DISPOSITION_ATTACHMENT;
                $attachment->encoding = Mime::ENCODING_BASE64;
                $attachment->filename = $a->get('name');
                if ($a->get('type')) {
                    $attachment->type = $a->get('type');
                }
                $attachmentPartList[] = $attachment;
            }
        }

        if (!empty($attachmentInlineCollection)) {
            foreach ($attachmentInlineCollection as $a) {
                $fileName = 'data/upload/' . $a->id;
                $attachment = new MimePart(file_get_contents($fileName));
                $attachment->disposition = Mime::DISPOSITION_INLINE;
                $attachment->encoding = Mime::ENCODING_BASE64;
                $attachment->id = $a->id;
                if ($a->get('type')) {
                    $attachment->type = $a->get('type');
                }
                $attachmentPartList[] = $attachment;
            }
        }


        $message->setSubject($email->get('name'));

        $body = new MimeMessage();

        $textPart = new MimePart($email->getBodyPlainForSending());
        $textPart->type = 'text/plain';
        $textPart->encoding = Mime::ENCODING_QUOTEDPRINTABLE;
        $textPart->charset = 'utf-8';

        if ($email->get('isHtml')) {
            $htmlPart = new MimePart($email->getBodyForSending());
            $htmlPart->encoding = Mime::ENCODING_QUOTEDPRINTABLE;
            $htmlPart->type = 'text/html';
            $htmlPart->charset = 'utf-8';
        }

        if (!empty($attachmentPartList)) {
            $messageType = 'multipart/related';
            if ($email->get('isHtml')) {
                $content = new MimeMessage();
                $content->addPart($textPart);
                $content->addPart($htmlPart);

                $messageType = 'multipart/mixed';

                $contentPart = new MimePart($content->generateMessage());
                $contentPart->type = "multipart/alternative;\n boundary=\"" . $content->getMime()->boundary() . '"';

                $body->addPart($contentPart);
            } else {
                $body->addPart($textPart);
            }

            foreach ($attachmentPartList as $attachmentPart) {
                $body->addPart($attachmentPart);
            }

        } else {
            if ($email->get('isHtml')) {
                $body->setParts(array($textPart, $htmlPart));
                $messageType = 'multipart/alternative';
            } else {
                $body = $email->getBodyPlainForSending();
                $messageType = 'text/plain';
            }
        }

        $message->setBody($body);

        if (!$message->getHeaders()->has('content-type')) {
            $contentTypeHeader = new \Zend\Mail\Header\ContentType();
            $message->getHeaders()->addHeader($contentTypeHeader);
        }
        $message->getHeaders()->get('content-type')->setType($messageType);

        $message->setEncoding('UTF-8');

        try {
            $rand = mt_rand(1000, 9999);

            if ($email->get('parentType') && $email->get('parentId')) {
                $messageId = '' . $email->get('parentType') .'/' . $email->get('parentId') . '/' . time() . '/' . $rand . '@espo';
            } else {
                $messageId = '' . md5($email->get('name')) . '/' . time() . '/' . $rand .  '@espo';
            }

            $messageIdHeader = new \Zend\Mail\Header\MessageId();
            $messageIdHeader->setId($messageId);
            $message->getHeaders()->addHeader($messageIdHeader);

            $this->transport->send($message);

            $email->set('messageId', '<' . $messageId . '>');
            $email->set('status', 'Sent');
            $email->set('dateSent', date("Y-m-d H:i:s"));
        } catch (\Exception $e) {
            throw new Error($e->getMessage(), 500);
        }

        $this->useGlobal();
    }
}

