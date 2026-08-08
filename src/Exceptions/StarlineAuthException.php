<?php namespace StarlineApi\Exceptions;
/**
 * Ошибка авторизации (SLID): неверные App ID/Secret, логин/пароль,
 * истёкший user_token или slnet.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
class StarlineAuthException extends StarlineException { }