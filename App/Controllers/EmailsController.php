<?php

namespace App\Controllers;

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;

class EmailsController
{
  private $email;
  private $subject;
  private $message;
  private $title;
  private $image;

  public function __construct($email, $subject, $title = 'Axpem')
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    $this->email = $email;
    $this->subject = $subject;
    $this->title = $title;
    $this->setImage($_ENV['URL_IMAGE'] . "/assets/images/Logo-Axpem-1024x734.png");
  }

  public function send()
  {
    try {
      $mail = new PHPMailer(true);
      $mail->isSMTP();
      $mail->Host = 'mail.logicsoftwares.com.br';
      $mail->SMTPAuth = true;
      $mail->Username = 'noreply@logicsoftwares.com.br';
      $mail->Password = '6CTAE)Fj2I3s';
      $mail->SMTPSecure = 'ssl';
      $mail->Port = 465;

      $mail->setFrom('noreply@logicsoftwares.com.br', $this->title);

      $mail->addAddress($this->email);

      $mail->isHTML(true);
      $mail->Subject = $this->subject;
      $mail->Body = $this->message;
      $mail->CharSet = 'UTF-8';

      if ($mail->send()) {
        return true;
      } else {
        return false;
      }
    } catch (\Exception $e) {
      throw new \Exception($e->getMessage());
    }
  }

  public function templateEmail($text)
  {
    $html = file_get_contents(dirname(__DIR__, 2) . '/App/Emails/EmailTemplate.html');
    $html = str_replace('{{content}}', $text, $html);
    return $html;
  }

  /**
   * Get the value of email
   */
  public function getEmail()
  {
    return $this->email;
  }

  /**
   * Set the value of email
   *
   * @return  self
   */
  public function setEmail($email)
  {
    $this->email = $email;

    return $this;
  }

  /**
   * Get the value of subject
   */
  public function getSubject()
  {
    return $this->subject;
  }

  /**
   * Set the value of subject
   *
   * @return  self
   */
  public function setSubject($subject)
  {
    $this->subject = $subject;

    return $this;
  }

  /**
   * Get the value of message
   */
  public function getMessage()
  {
    return $this->message;
  }

  /**
   * Set the value of message
   *
   * @return  self
   */
  public function setMessage($message)
  {
    $this->message = $message;

    return $this;
  }

  /**
   * Get the value of title
   */
  public function getTitle()
  {
    return $this->title;
  }

  /**
   * Set the value of title
   *
   * @return  self
   */
  public function setTitle($title)
  {
    $this->title = $title;

    return $this;
  }

  /**
   * Get the value of image
   */
  public function getImage()
  {
    return $this->image;
  }

  /**
   * Set the value of image
   *
   * @return  self
   */
  public function setImage($image)
  {
    $this->image = $image;

    return $this;
  }
}
