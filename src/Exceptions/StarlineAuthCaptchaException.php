<?php namespace Cruide\StarlineApi\Exceptions;

/**
 * Исключение при необходимости капчи или SMS-подтверждения.
 *
 * Выбрасывается, когда user/login возвращает state=0 и требует
 * captchaSid/captchaImg (капча) или phone (SMS-код).
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
class StarlineAuthCaptchaException extends StarlineAuthException
{
    /**
     * @param string $message Сообщение об ошибке.
     * @param string|null $captchaSid Идентификатор капчи (если требуется капча).
     * @param string|null $captchaImg URL изображения капчи.
     * @param string|null $phone Замаскированный номер телефона (если требуется SMS).
     */
    public function __construct(
        string $message,
        private ?string $captchaSid = null,
        private ?string $captchaImg = null,
        private ?string $phone = null
    ) {
        parent::__construct($message);
    }

    public function getCaptchaSid(): ?string
    {
        return $this->captchaSid;
    }

    public function getCaptchaImg(): ?string
    {
        return $this->captchaImg;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    /**
     * Требуется ли капча (не SMS).
     */
    public function isCaptchaRequired(): bool
    {
        return $this->captchaSid !== null;
    }

    /**
     * Требуется ли SMS-код.
     */
    public function isSmsRequired(): bool
    {
        return $this->phone !== null;
    }
}
