<?php
// src/Service/Notifier.php

namespace App\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Notifier
{
    private string $host;
    private string $username;
    private string $password;
    private int $port;
    private string $encryption;
    private string $destination;
    private array $destinations;


    public function __construct(
        string $MAIL_HOST,
        string $MAIL_USERNAME,
        string $MAIL_PASSWORD,
        int $MAIL_PORT,
        string $MAIL_ENCRYPTION,
        string $MAIL_DESTINATION
    ) {
        // Mapeamos las variables inyectadas a propiedades internas
        $this->host = $MAIL_HOST;
        $this->username = $MAIL_USERNAME;
        $this->password = $MAIL_PASSWORD;
        $this->port = $MAIL_PORT;
        $this->encryption = $MAIL_ENCRYPTION;
        $this->destinations = array_map('trim', explode(',', $MAIL_DESTINATION));
    }

    public function getDestination(): string
    {
        return implode(', ', $this->destinations);
    }
    /**
     * Envía un correo de notificación de fallo usando PHPMailer.
     * @param string $subject Asunto del correo (ej: ALERTA: FALLO EN PROCESO X)
     * @param string $body Mensaje detallado del error.
     * @return bool True si se envió con éxito, False en caso contrario.
     * @param string|null $attachmentPath (Opcional) Ruta absoluta del archivo a adjuntar
     */
    public function sendFailureEmail(string $subject, string $body,?string $attachmentPath = null): bool
    {
        $mail = new PHPMailer(true);

        try {
            // Configuración SMTP
            $mail->isSMTP();
            $mail->Host       = $this->host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->username;
            $mail->Password   = $this->password;
            
            // Ajuste de encriptación
            $mail->SMTPSecure = ($this->encryption === 'ssl') 
                              ? PHPMailer::ENCRYPTION_SMTPS 
                              : PHPMailer::ENCRYPTION_STARTTLS; 
            
            $mail->Port       = $this->port;
            $mail->CharSet    = 'UTF-8';

            // Remitente y Destinatario
            $mail->setFrom($this->username, 'Orquestador FinPay');
            foreach ($this->destinations as $address) {
                if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $mail->addAddress($address);
                }
            }
            //Si hay adjuntos para enviar, segun se haya recibido del parametro opcional de entrada.
            if ($attachmentPath !== null && file_exists($attachmentPath)) {
                $mail->addAttachment($attachmentPath);
            }

            // Contenido
            $mail->isHTML(false); // Enviamos texto plano
            $mail->Subject = $subject;
            $mail->Body    = $body;
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            // Este error significa que el *envío* del correo falló. 
            // Lo ideal es registrar esto también en el log del sistema.
            error_log("PHPMailer Error de envío: {$mail->ErrorInfo} | Excepción: {$e->getMessage()}");
            return false;
        }
    }
}