<?php

namespace App\Utils;

use Laminas\Db\Sql\Sql;
use Laminas\Db\TableGateway\TableGatewayInterface;
use App\Utils\SmtpMailer;

class ErrorMailer
{
    protected $uri;
    protected $server;
    protected $errors;
    protected $mailer;
    protected $details;
    protected $exception;

    public function __construct(
        TableGatewayInterface $errors,
        SmtpMailer $smtpMailer
    )
    {
        $this->errors = $errors;
        $this->mailer = $smtpMailer;
        $this->adapter = $errors->getAdapter();
    }

    public function setUri(string $uri)
    {
        $this->uri = $uri;
    }

    public function setServerParams($server)
    {
        $this->server = $server;
    }

    public function setException($e)
    {
        $this->exception = $e;
    }

    public function getException()
    {
        return $this->exception;
    }

    public function setDetails($details)
    {
        $this->details = $details;
    }

    public function send()
    {
        $e = $this->getException();
        $errorId = md5($e->getFile().$e->getLine().date('Y-m-d'));

        // if the "errorId" is not in the db, let's send an e-mail and save the error to the db.
        //
        $sql = new Sql($this->adapter);
        $select = $sql->select();
        $select->from('errors');
        $select->where(['errorId' => $errorId]);

        $statement = $sql->prepareStatementForSqlObject($select);
        $resultSet = $statement->execute();
        $row = $resultSet->current();
        $statement->getResource()->closeCursor();

        if (false == $row) {
            $data = $e->getTrace();
            $trace = array_map(
                function ($a) {
                    if (isset($a['file'])) {
                        $a['file'] = str_replace(PROJECT_ROOT, '', $a['file']);
                    }
                    return $a;
                },
                $data
            );
            $title = get_class($e);
            $filename = str_replace(PROJECT_ROOT, '', $e->getFile());
            $line = $e->getLine();
            $message = $e->getMessage();
            $json = [
                'title' => $title,
                'file'  => $filename,
                'line'  => $line,
                'error' => $message,
                'trace' => $trace,
            ];
            $errorString = print_r($json, true);

            // Mail body

            $subject = 'Production Error: #'.$errorId.' #'.$filename.' #'.$line;
            $body = '<b>Url:</b>'.$this->uri.'<br>';
            $body.= '<b>Error id:</b> '.$errorId.'<br>';
            $body.= '<b>Date: '.date('d-m-Y H:i:s').'</b>'.'<br><br>';
            $body.= '<pre>'.print_r($this->server, true).'<pre><br>';
            $body.= '<pre>'.$errorString.'<pre><br>';
            if (! empty($this->details)) {
                $body.='<pre>'.(string)$this->details.'</pre>';
            }
            $this->mailer->to("me@example.com", "My Name Surname");
            $this->mailer->subject("Application Error");
            $this->mailer->body($body);
            $this->mailer->send();

            // save to db
            // 
            $data = array();
            $data['errorId'] = (string)$errorId;
            $data['errorTitle'] = (string)$title;
            $data['errorFile'] = (string)$filename;
            $data['errorLine'] = $line;
            $data['errorMessage'] = (string)$message;
            $data['errorDate'] = date('Y-m-d');
            $this->errors->insert($data);
        }
    }
}