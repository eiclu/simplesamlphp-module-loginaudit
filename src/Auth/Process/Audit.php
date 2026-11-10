<?php

declare(strict_types=1);

namespace SimpleSAML\Module\loginaudit\Auth\Process;

use SimpleSAML\Auth\ProcessingFilter;
use SimpleSAML\Logger;

/**
 * Authentication processing filter that writes one AUDIT-LOGIN line per successful login.
 *
 * Example (config.php or the metadata of an IdP/SP):
 *
 *   'authproc.idp' => [
 *       90 => [
 *           'class' => 'loginaudit:Audit',
 *           'user_attribute' => 'uid',
 *           'name_attribute' => 'displayName',
 *           'email_attribute' => 'mail',
 *       ],
 *   ],
 */
class Audit extends ProcessingFilter
{
    private string $userAttribute;
    private string $nameAttribute;
    private string $emailAttribute;


    /**
     * @param array<string, mixed> $config
     * @param mixed $reserved
     */
    public function __construct(array &$config, $reserved)
    {
        parent::__construct($config, $reserved);

        $this->userAttribute = (string) ($config['user_attribute'] ?? 'uid');
        $this->nameAttribute = (string) ($config['name_attribute'] ?? 'displayName');
        $this->emailAttribute = (string) ($config['email_attribute'] ?? 'mail');
    }


    /**
     * @param array<string, mixed> $state
     */
    public function process(array &$state): void
    {
        $attributes = $state['Attributes'] ?? [];

        // On an SP the IdP is in saml:sp:IdP; on an IdP the entity itself is the identity provider.
        $idp = $state['saml:sp:IdP']
            ?? $state['Source']['entityid']
            ?? $state['IdPMetadata']['entityid']
            ?? '';

        Logger::notice(sprintf(
            'AUDIT-LOGIN: user="%s" name="%s" email="%s" idp="%s" ip="%s"',
            self::clean($attributes[$this->userAttribute] ?? ''),
            self::clean($attributes[$this->nameAttribute] ?? ''),
            self::clean($attributes[$this->emailAttribute] ?? ''),
            self::clean($idp),
            self::clean($_SERVER['REMOTE_ADDR'] ?? ''),
        ));
    }


    /**
     * First value of an attribute, with characters that would break the log format removed.
     *
     * @param mixed $value
     */
    private static function clean(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return str_replace(['"', "\r", "\n"], ['', ' ', ' '], (string) $value);
    }
}
