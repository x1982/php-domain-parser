<?php

/**
 * PHP Domain Parser: Public Suffix List based URL parsing.
 *
 * @see http://github.com/jeremykendall/php-domain-parser for the canonical source repository
 *
 * @copyright Copyright (c) 2017 Jeremy Kendall (http://jeremykendall.net)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Pdp;

use Pdp\Exception\InvalidDomain;
use TypeError;

/**
 * @internal Domain name validator
 *
 * @author Ignace Nyamagana Butera <nyamsprod@gmail.com>
 */
trait IDNAConverterTrait
{
    /**
     * Get and format IDN conversion error message.
     *
     * @param int $error_bit
     *
     * @return string
     */
    private static function getIdnErrors(int $error_bit): string
    {
        /**
         * IDNA errors.
         *
         * @see http://icu-project.org/apiref/icu4j/com/ibm/icu/text/IDNA.Error.html
         */
        static $idn_errors = [
            IDNA_ERROR_EMPTY_LABEL => 'a non-final domain name label (or the whole domain name) is empty',
            IDNA_ERROR_LABEL_TOO_LONG => 'a domain name label is longer than 63 bytes',
            IDNA_ERROR_DOMAIN_NAME_TOO_LONG => 'a domain name is longer than 255 bytes in its storage form',
            IDNA_ERROR_LEADING_HYPHEN => 'a label starts with a hyphen-minus ("-")',
            IDNA_ERROR_TRAILING_HYPHEN => 'a label ends with a hyphen-minus ("-")',
            IDNA_ERROR_HYPHEN_3_4 => 'a label contains hyphen-minus ("-") in the third and fourth positions',
            IDNA_ERROR_LEADING_COMBINING_MARK => 'a label starts with a combining mark',
            IDNA_ERROR_DISALLOWED => 'a label or domain name contains disallowed characters',
            IDNA_ERROR_PUNYCODE => 'a label starts with "xn--" but does not contain valid Punycode',
            IDNA_ERROR_LABEL_HAS_DOT => 'a label contains a dot=full stop',
            IDNA_ERROR_INVALID_ACE_LABEL => 'An ACE label does not contain a valid label string',
            IDNA_ERROR_BIDI => 'a label does not meet the IDNA BiDi requirements (for right-to-left characters)',
            IDNA_ERROR_CONTEXTJ => 'a label does not meet the IDNA CONTEXTJ requirements',
        ];

        $res = [];
        foreach ($idn_errors as $error => $reason) {
            if ($error_bit & $error) {
                $res[] = $reason;
            }
        }

        return empty($res) ? 'Unknown IDNA conversion error.' : implode(', ', $res).'.';
    }

    /**
     * Converts the input to its IDNA ASCII form.
     *
     * This method returns the string converted to IDN ASCII form
     *
     * @param string $domain
     *
     * @throws InvalidDomain if the string can not be converted to ASCII using IDN UTS46 algorithm
     *
     * @return string
     */
    private function idnToAscii(string $domain): string
    {
        static $pattern = '/[^\x20-\x7f]/';
        if (!preg_match($pattern, $domain)) {
            return strtolower($domain);
        }

        $output = idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46, $arr);
        if (!$arr['errors']) {
            return $output;
        }

        throw new InvalidDomain(sprintf('The host `%s` is invalid : %s', $domain, self::getIdnErrors($arr['errors'])));
    }

    /**
     * Converts the input to its IDNA UNICODE form.
     *
     * This method returns the string converted to IDN UNICODE form
     *
     * @param string $domain
     *
     * @throws InvalidDomain if the string can not be converted to UNICODE using IDN UTS46 algorithm
     *
     * @return string
     */
    private function idnToUnicode(string $domain): string
    {
        $output = idn_to_utf8($domain, 0, INTL_IDNA_VARIANT_UTS46, $arr);
        if (!$arr['errors']) {
            return $output;
        }

        throw new InvalidDomain(sprintf('The host `%s` is invalid : %s', $domain, self::getIdnErrors($arr['errors'])));
    }

    /**
     * Filter and format the domain to ensure it is valid.
     *
     * Returns an array containing the formatted domain name in lowercase
     * with its associated labels in reverse order
     *
     * For example: setLabels('wWw.uLb.Ac.be') should return ['www.ulb.ac.be', ['be', 'ac', 'ulb', 'www']];
     *
     * @param mixed $domain
     *
     * @throws InvalidDomain If the domain is invalid
     *
     * @return string[]
     */
    private function setLabels($domain = null): array
    {
        if ($domain instanceof DomainInterface) {
            return iterator_to_array($domain, false);
        }

        if (null === $domain) {
            return [];
        }

        if ('' === $domain) {
            return [''];
        }

        if (!is_scalar($domain) && !method_exists($domain, '__toString')) {
            throw new TypeError(sprintf('The domain must be a scalar, a stringable object, a DomainInterface object or null; `%s` given', gettype($domain)));
        }

        $domain = (string) $domain;
        if (filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidDomain(sprintf('The domain `%s` is invalid: this is an IPv4 host', $domain));
        }

        $formatted_domain = rawurldecode($domain);

        // Note that unreserved is purposely missing . as it is used to separate labels.
        static $domain_name = '/(?(DEFINE)
                (?<unreserved>[a-z0-9_~\-])
                (?<sub_delims>[!$&\'()*+,;=])
                (?<encoded>%[A-F0-9]{2})
                (?<reg_name>(?:(?&unreserved)|(?&sub_delims)|(?&encoded)){1,63})
            )
            ^(?:(?&reg_name)\.){0,126}(?&reg_name)\.?$/ix';
        if (preg_match($domain_name, $formatted_domain)) {
            return array_reverse(explode('.', strtolower($formatted_domain)));
        }

        // a domain name can not contains URI delimiters or space
        static $gen_delims = '/[:\/?#\[\]@ ]/';
        if (preg_match($gen_delims, $formatted_domain)) {
            throw new InvalidDomain(sprintf('The domain `%s` is invalid: it contains invalid characters', $domain));
        }

        // if the domain name does not contains UTF-8 chars then it is malformed
        static $pattern = '/[^\x20-\x7f]/';
        if (!preg_match($pattern, $formatted_domain)) {
            throw new InvalidDomain(sprintf('The domain `%s` is invalid: the labels are malformed', $domain));
        }

        //if a domain name contains UTF-8 chars it must be convertible using IDNA UTS46
        $ascii_domain = idn_to_ascii($formatted_domain, 0, INTL_IDNA_VARIANT_UTS46, $arr);
        if (0 === $arr['errors']) {
            return array_reverse(explode('.', $this->idnToUnicode($ascii_domain)));
        }

        throw new InvalidDomain(sprintf('The domain `%s` is invalid : %s', $domain, self::getIdnErrors($arr['errors'])));
    }
}
