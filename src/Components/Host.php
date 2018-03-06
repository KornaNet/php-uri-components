<?php
/**
 * League.Uri (http://uri.thephpleague.com)
 *
 * @package    League\Uri
 * @subpackage League\Uri\Components
 * @author     Ignace Nyamagana Butera <nyamsprod@gmail.com>
 * @license    https://github.com/thephpleague/uri-components/blob/master/LICENSE (MIT License)
 * @version    1.7.1
 * @link       https://github.com/thephpleague/uri-components
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace League\Uri\Components;

use League\Uri\PublicSuffix\Cache;
use League\Uri\PublicSuffix\CurlHttpClient;
use League\Uri\PublicSuffix\ICANNSectionManager;
use League\Uri\PublicSuffix\Rules;
use Traversable;

/**
 * Value object representing a URI Host component.
 *
 * Instances of this interface are considered immutable; all methods that
 * might change state MUST be implemented such that they retain the internal
 * state of the current instance and return an instance that contains the
 * changed state.
 *
 * @package    League\Uri
 * @subpackage League\Uri\Components
 * @author     Ignace Nyamagana Butera <nyamsprod@gmail.com>
 * @since      1.0.0
 * @see        https://tools.ietf.org/html/rfc3986#section-3.2.2
 */
class Host extends AbstractHierarchicalComponent implements ComponentInterface
{
    const LOCAL_LINK_PREFIX = '1111111010';

    const INVALID_ZONE_ID_CHARS = "?#@[]\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    const STARTING_LABEL_CHARS = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    const SUB_DELIMITERS = '!$&\'()*+,;=';

    /**
     * Tell whether the Host is a domain name
     *
     * @var bool
     */
    protected $host_as_domain_name = false;

    /**
     * Tell whether the Host is an IPv4
     *
     * @deprecated 1.8.0 No longer used by internal code and not recommend
     *
     * @var bool
     */
    protected $host_as_ipv4 = false;

    /**
     * Tell whether the Host is an IPv6
     *
     * @deprecated 1.8.0 No longer used by internal code and not recommend
     *
     * @var bool
     */
    protected $host_as_ipv6 = false;

    /**
     * Tell the host IP version used
     *
     * @var string|null
     */
    protected $ip_version;

    /**
     * Tell whether the Host contains a ZoneID
     *
     * @var bool
     */
    protected $has_zone_identifier = false;

    /**
     * Host separator
     *
     * @var string
     */
    protected static $separator = '.';

    /**
     * Hostname public info
     *
     * @var array
     */
    protected $hostname = [];

    /**
     * @var Rules|null
     */
    protected $resolver;

    /**
     * {@inheritdoc}
     */
    public static function __set_state(array $properties): self
    {
        $host = static::createFromLabels(
            $properties['data'],
            $properties['is_absolute'],
            $properties['resolver'] ?? null
        );

        $host->hostname = $properties['hostname'];

        return $host;
    }

    /**
     * Returns a new instance from an array or a traversable object.
     *
     * @param Traversable|array $data     The segments list
     * @param int               $type     One of the constant IS_ABSOLUTE or IS_RELATIVE
     * @param null|Rules        $resolver
     *
     * @throws Exception If $type is not a recognized constant
     *
     * @return static
     */
    public static function createFromLabels($data, int $type = self::IS_RELATIVE, Rules $resolver = null): self
    {
        static $type_list = [self::IS_ABSOLUTE => 1, self::IS_RELATIVE => 1];

        $data = static::filterIterable($data);
        if (!isset($type_list[$type])) {
            throw Exception::fromInvalidFlag($type);
        }

        if ([] === $data) {
            return new static(null, $resolver);
        }

        if ([''] === $data) {
            return new static('', $resolver);
        }

        $host = implode(static::$separator, array_reverse($data));
        if (self::IS_ABSOLUTE === $type) {
            return new static($host.static::$separator, $resolver);
        }

        return new static($host, $resolver);
    }

    /**
     * Returns a formatted host string.
     *
     * DEPRECATION WARNING! This method will be removed in the next major point release
     *
     * @deprecated 1.8.0 No longer used by internal code and not recommend
     *
     * @param array $data The segments list
     * @param int   $type
     *
     * @return string
     */
    protected static function format(array $data, int $type): string
    {
        $hostname = implode(static::$separator, array_reverse($data));
        if (self::IS_ABSOLUTE === $type) {
            return $hostname.static::$separator;
        }

        return $hostname;
    }

    /**
     * Returns a host from an IP address.
     *
     * @param string     $ip
     * @param null|Rules $resolver
     *
     * @return static
     */
    public static function createFromIp(string $ip, Rules $resolver = null): self
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return new static($ip, $resolver);
        }

        if (false !== strpos($ip, '%')) {
            list($ipv6, $zoneId) = explode('%', rawurldecode($ip), 2) + [1 => ''];
            $ip = $ipv6.'%25'.rawurlencode($zoneId);
        }

        return new static('['.$ip.']', $resolver);
    }

    /**
     * New instance
     *
     * @param null|string $host
     * @param null|Rules  $resolver
     */
    public function __construct(string $host = null, Rules $resolver = null)
    {
        $this->data = $this->validate($host);
        $this->resolver = $resolver;
    }

    /**
     * Validates the submitted data.
     *
     * @param string|null $host
     *
     * @throws Exception If the host is invalid
     *
     * @return array
     */
    protected function validate(string $host = null): array
    {
        if (null === $host) {
            return [];
        }

        if ('' === $host) {
            return [''];
        }

        $host = $this->validateString($host);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->host_as_ipv4 = true;
            $this->ip_version = '4';

            return [$host];
        }

        $reg_name = strtolower(rawurldecode($host));
        if ($this->isValidDomain($reg_name)) {
            $this->host_as_domain_name = true;
            if (false !== strpos($reg_name, 'xn--')) {
                $reg_name = idn_to_utf8($reg_name, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            }
            $reg_name = $this->setIsAbsolute($reg_name);

            return array_reverse(explode('.', $reg_name));
        }

        if ($this->isValidRegisteredName($reg_name)) {
            return [$reg_name];
        }

        if ($this->isValidIpv6Hostname($host)) {
            $this->host_as_ipv6 = true;
            $this->has_zone_identifier = false !== strpos($host, '%');
            $this->ip_version = '6';

            return [$host];
        }

        if ($this->isValidIpFuture($host)) {
            preg_match('/^v(?<version>[A-F0-9]+)\./', substr($host, 1, -1), $matches);
            $this->ip_version = $matches['version'];

            return [$host];
        }

        throw new Exception(sprintf('The submitted host `%s` is invalid', $host));
    }

    /**
     * Set the FQDN property.
     *
     * @param string|null $str
     *
     * @return string|null
     */
    protected function setIsAbsolute(string $str = null)
    {
        if (null === $str) {
            return $str;
        }

        $this->is_absolute = self::IS_RELATIVE;
        if ('.' === substr($str, -1, 1)) {
            $this->is_absolute = self::IS_ABSOLUTE;
            return substr($str, 0, -1);
        }

        return $str;
    }

    /**
     * Validates an Ipv6 as Host
     *
     * @see http://tools.ietf.org/html/rfc6874#section-2
     * @see http://tools.ietf.org/html/rfc6874#section-4
     *
     * @param string $ipv6
     *
     * @return bool
     */
    protected function isValidIpv6Hostname(string $ipv6): bool
    {
        if ('[' !== ($ipv6[0] ?? '') || ']' !== substr($ipv6, -1)) {
            return false;
        }

        $ipv6 = substr($ipv6, 1, -1);
        if (false === ($pos = strpos($ipv6, '%'))) {
            return (bool) filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        }

        $scope_raw = substr($ipv6, $pos);
        if (strlen($scope_raw) !== mb_strlen($scope_raw)) {
            return false;
        }

        $scope = rawurldecode($scope_raw);
        if (strlen($scope) !== strcspn($scope, self::INVALID_ZONE_ID_CHARS)) {
            return false;
        }

        $ipv6 = substr($ipv6, 0, $pos);
        if (!filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        static $address_block = "\xfe\x80";

        return substr(inet_pton($ipv6) & $address_block, 0, 2) === $address_block;
    }

    /**
     * Validates an Ip future as host.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * @param string $ipfuture
     *
     * @return bool
     */
    private function isValidIpFuture(string $ipfuture): bool
    {
        if ('[' !== ($ipfuture[0] ?? '') || ']' !== substr($ipfuture, -1)) {
            return false;
        }

        static $pattern = '/^
            v(?<version>[A-F0-9]+)\.
            (?:
                (?<unreserved>[a-z0-9_~\-\.])|
                (?<sub_delims>[!$&\'()*+,;=:])  # also include the : character
            )+
        $/ix';

        return preg_match($pattern, substr($ipfuture, 1, -1), $matches)
            && !in_array($matches['version'], ['4', '6'], true);
    }

    /**
     * Validates a domain name as host.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * @param string $host
     *
     * @return bool
     */
    private function isValidDomain(string $host): bool
    {
        $host = strtolower(rawurldecode($host));
        if ('.' === $host[0]) {
            $host = substr($host, 1);
        }

        // Note that unreserved is purposely missing . as it is used to separate labels.
        static $reg_name = '/(?(DEFINE)
                (?<unreserved> [a-z0-9_~\-])
                (?<sub_delims> [!$&\'()*+,;=])
                (?<encoded> %[A-F0-9]{2})
                (?<reg_name> (?:(?&unreserved)|(?&sub_delims)|(?&encoded)){1,63})
            )
            ^(?:(?&reg_name)\.){0,126}(?&reg_name)\.?$/ix';
        static $gen_delims = '/[:\/?#\[\]@ ]/'; // Also includes space.
        if (preg_match($reg_name, $host)) {
            return true;
        }

        if (preg_match($gen_delims, $host)) {
            return false;
        }

        $res = idn_to_ascii($host, 0, INTL_IDNA_VARIANT_UTS46, $arr);

        return 0 === $arr['errors'];
    }

    /**
     * Validates a registered name as host.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * @param string $host
     *
     * @return bool
     */
    private function isValidRegisteredName(string $host): bool
    {
        static $reg_name = '/^(
            (?<unreserved>[a-z0-9_~\-\.])|
            (?<sub_delims>[!$&\'()*+,;=])|
            (?<encoded>%[A-F0-9]{2})
        )+$/x';
        if (preg_match($reg_name, $host)) {
            return true;
        }

        static $gen_delims = '/[:\/?#\[\]@ ]/'; // Also includes space.
        if (preg_match($gen_delims, $host)) {
            return false;
        }

        $host = idn_to_ascii($host, 0, INTL_IDNA_VARIANT_UTS46, $arr);

        return !$arr['errors'];
    }

    /**
     * Returns whether the hostname is valid.
     *
     * DEPRECATION WARNING! This method will be removed in the next major point release
     *
     * @deprecated 1.8.0 No longer used by internal code and not recommend
     *
     * A valid registered name MUST:
     *
     * - contains at most 127 subdomains deep
     * - be limited to 255 octets in length
     *
     * @see https://en.wikipedia.org/wiki/Subdomain
     * @see https://tools.ietf.org/html/rfc1035#section-2.3.4
     * @see https://blogs.msdn.microsoft.com/oldnewthing/20120412-00/?p=7873/
     *
     * @param string $host
     *
     * @return bool
     */
    protected function isValidHostname(string $host): bool
    {
        $labels = array_map([$this, 'toAscii'], explode('.', $host));

        return 127 > count($labels)
            && 253 > strlen(implode('.', $labels))
            && $labels === array_filter($labels, [$this, 'isValidLabel']);
    }

    /**
     * Returns whether the registered name label is valid
     *
     * DEPRECATION WARNING! This method will be removed in the next major point release
     *
     * @deprecated 1.8.0 No longer used by internal code and not recommend
     *
     * A valid registered name label MUST:
     *
     * - not be empty
     * - contain 63 characters or less
     * - conform to the following ABNF
     *
     * reg-name = *( unreserved / pct-encoded / sub-delims )
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * @param string $label
     *
     * @return bool
     */
    protected function isValidLabel($label): bool
    {
        return is_string($label)
            && '' != $label
            && 63 >= strlen($label)
            && strlen($label) == strspn($label, self::STARTING_LABEL_CHARS.'-_~'.self::SUB_DELIMITERS);
    }

    /**
     * {@inheritdoc}
     */
    public function __debugInfo()
    {
        $this->lazyloadInfo();

        return array_merge([
            'component' => $this->getContent(),
            'labels' => $this->data,
            'is_absolute' => (bool) $this->is_absolute,
        ], $this->hostname);
    }

    /**
     * Resolve domain name information
     */
    protected function lazyloadInfo()
    {
        if (!empty($this->hostname)) {
            return;
        }

        if (!$this->host_as_domain_name) {
            $this->hostname = $this->hostname = [
                'isPublicSuffixValid' => false,
                'publicSuffix' => '',
                'registrableDomain' => '',
                'subDomain' => '',
            ];

            return;
        }

        $host = $this->getContent();
        if ($this->isAbsolute()) {
            $host = substr($host, 0, -1);
        }

        $this->resolver = $this->resolver ?? (new ICANNSectionManager(new Cache(), new CurlHttpClient()))->getRules();
        $domain = $this->resolver->resolve($host);

        $this->hostname = [
            'isPublicSuffixValid' => $domain->isValid(),
            'publicSuffix' => (string) $domain->getPublicSuffix(),
            'registrableDomain' => (string) $domain->getRegistrableDomain(),
            'subDomain' => (string) $domain->getSubDomain(),
        ];
    }

    /**
     * Return the host public suffix
     *
     * @return string
     */
    public function getPublicSuffix(): string
    {
        $this->lazyloadInfo();

        return $this->hostname['publicSuffix'];
    }

    /**
     * Return the host registrable domain.
     *
     * DEPRECATION WARNING! This method will be removed in the next major point release
     *
     * @deprecated 1.5.0 Typo fix in name
     * @see        Host::getRegistrableDomain
     *
     * @return string
     */
    public function getRegisterableDomain(): string
    {
        return $this->getRegistrableDomain();
    }

    /**
     * Return the host registrable domain
     *
     * @return string
     */
    public function getRegistrableDomain(): string
    {
        $this->lazyloadInfo();

        return $this->hostname['registrableDomain'];
    }

    /**
     * Return the hostname subdomain
     *
     * @return string
     */
    public function getSubDomain(): string
    {
        $this->lazyloadInfo();

        return $this->hostname['subDomain'];
    }

    /**
     * Tell whether the current public suffix is valid
     *
     * @return bool
     */
    public function isPublicSuffixValid(): bool
    {
        $this->lazyloadInfo();

        return $this->hostname['isPublicSuffixValid'];
    }

    /**
     * {@inheritdoc}
     */
    public function isNull(): bool
    {
        return null === $this->getContent();
    }

    /**
     * {@inheritdoc}
     */
    public function isEmpty(): bool
    {
        return '' == $this->getContent();
    }

    /**
     * Returns whether or not the host is an IP address
     *
     * @return bool
     */
    public function isIp(): bool
    {
        return null !== $this->ip_version;
    }

    /**
     * Returns whether or not the host is an IPv4 address
     *
     * @return bool
     */
    public function isIpv4(): bool
    {
        return '4' === $this->ip_version;
    }

    /**
     * Returns whether or not the host is an IPv6 address
     *
     * @return bool
     */
    public function isIpv6(): bool
    {
        return '6' === $this->ip_version;
    }

    /**
     * Returns whether or not the host has a ZoneIdentifier
     *
     * @return bool
     *
     * @see http://tools.ietf.org/html/rfc6874#section-4
     */
    public function hasZoneIdentifier(): bool
    {
        return $this->has_zone_identifier;
    }

    /**
     * Returns whether or not the host is an IPv6 address
     *
     * @return bool
     */
    public function isIpFuture(): bool
    {
        return !in_array($this->ip_version, [null, '4', '6'], true);
    }

    /**
     * Returns whether or not the host is an IPv6 address
     *
     * @return bool
     */
    public function isDomain(): bool
    {
        return $this->host_as_domain_name;
    }

    /**
     * Returns an array representation of the Host
     *
     * @return array
     */
    public function getLabels(): array
    {
        return $this->data;
    }

    /**
     * Retrieves a single host label.
     *
     * Retrieves a single host label. If the label offset has not been set,
     * returns the default value provided.
     *
     * @param int   $offset  the label offset
     * @param mixed $default Default value to return if the offset does not exist.
     *
     * @return mixed
     */
    public function getLabel(int $offset, $default = null)
    {
        if ($offset < 0) {
            $offset += count($this->data);
        }

        return $this->data[$offset] ?? $default;
    }

    /**
     * Returns the associated key for each label.
     *
     * If a value is specified only the keys associated with
     * the given value will be returned
     *
     * @param mixed ...$args the total number of argument given to the method
     *
     * @return array
     */
    public function keys(...$args): array
    {
        if (empty($args)) {
            return array_keys($this->data);
        }

        return array_keys($this->data, $this->toIdn($this->validateString($args[0])), true);
    }

    /**
     * Convert domain name to IDNA ASCII form.
     *
     * Conversion is done only if the label contains the ACE prefix 'xn--'
     * if a '%' sub delimiter is detected the label MUST be rawurldecode prior to
     * making the conversion
     *
     * @param string $label
     *
     * @return string|false
     */
    protected function toIdn(string $label)
    {
        $label = rawurldecode($label);
        if (0 !== strpos($label, 'xn--')) {
            return $label;
        }

        return idn_to_utf8($label, 0, INTL_IDNA_VARIANT_UTS46);
    }

    /**
     * {@inheritdoc}
     */
    public function getContent(int $enc_type = self::RFC3986_ENCODING)
    {
        $this->assertValidEncoding($enc_type);

        if ([] === $this->data) {
            return null;
        }

        if ($this->isIp() || !$this->isDomain()) {
            return $this->data[0];
        }

        $host = implode(static::$separator, array_reverse($this->data));
        if ($enc_type !== self::RFC3987_ENCODING) {
            $host = $this->toAscii($host);
        }

        if (self::IS_ABSOLUTE !== $this->is_absolute) {
            return $host;
        }

        return $host.static::$separator;
    }

    /**
     * Convert a registered name label to its IDNA ASCII form.
     *
     * Conversion is done only if the label contains none valid label characters
     * if a '%' sub delimiter is detected the label MUST be rawurldecode prior to
     * making the conversion
     *
     * @param string $label
     *
     * @return string|false
     */
    protected function toAscii(string $label)
    {
        $label = strtolower(rawurldecode($label));
        if (!preg_match('/\pL/u', $label)) {
            return $label;
        }

        return idn_to_ascii($label, 0, INTL_IDNA_VARIANT_UTS46);
    }

    /**
     * Retrieve the IP component If the Host is an IP adress.
     *
     * If the host is a not an IP this method will return null
     *
     * @return string|null
     */
    public function getIp()
    {
        if (null === $this->ip_version) {
            return null;
        }

        if ('4' === $this->ip_version) {
            return $this->data[0];
        }

        $ip = substr($this->data[0], 1, -1);
        if ('6' !== $this->ip_version) {
            return preg_replace('/^v(?<version>[A-F0-9]+)\./', '', $ip);
        }

        if (false === ($pos = strpos($ip, '%'))) {
            return $ip;
        }

        return substr($ip, 0, $pos).'%'.rawurldecode(substr($ip, $pos + 3));
    }

    /**
     * Returns the IP version
     *
     * If the host is a not an IP this method will return null
     *
     * @return string|null
     */
    public function getIpVersion()
    {
        return $this->ip_version;
    }

    /**
     * {@inheritdoc}
     */
    public function withContent($value): ComponentInterface
    {
        if ($value === $this->getContent()) {
            return $this;
        }

        $new = new static($value, $this->resolver);
        if (!empty($this->hostname)) {
            $new->lazyloadInfo();
        }

        return $new;
    }

    /**
     * Return an host without its zone identifier according to RFC6874
     *
     * This method MUST retain the state of the current instance, and return
     * an instance without the host zone identifier according to RFC6874
     *
     * @see http://tools.ietf.org/html/rfc6874#section-4
     *
     * @return static
     */
    public function withoutZoneIdentifier(): self
    {
        if (!$this->has_zone_identifier) {
            return $this;
        }

        $new = new static(substr($this->data[0], 0, strpos($this->data[0], '%')).']', $this->resolver);
        $new->hostname = $this->hostname;

        return $new;
    }

    /**
     * Returns a host instance with its Root label
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * @return static
     */
    public function withRootLabel(): self
    {
        if ($this->is_absolute == self::IS_ABSOLUTE || $this->isIp()) {
            return $this;
        }

        $clone = clone $this;
        $clone->is_absolute = self::IS_ABSOLUTE;

        return $clone;
    }

    /**
     * Returns a host instance without the Root label
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * @return static
     */
    public function withoutRootLabel(): self
    {
        if ($this->is_absolute == self::IS_RELATIVE || $this->isIp()) {
            return $this;
        }

        $clone = clone $this;
        $clone->is_absolute = self::IS_RELATIVE;

        return $clone;
    }

    /**
     * Returns an instance with the specified component prepended
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the modified component with the prepended data
     *
     * @param string $host the component to append
     *
     * @return static
     */
    public function prepend(string $host): self
    {
        $labels = array_merge($this->data, $this->filterComponent($host));
        if ($this->data === $labels) {
            return $this;
        }

        $new = self::createFromLabels($labels, $this->is_absolute, $this->resolver);
        if (!empty($this->hostname)) {
            $new->lazyloadInfo();
        }

        return $new;
    }

    /**
     * Returns an instance with the specified component appended
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the modified component with the appended data
     *
     * @param string $host the component to append
     *
     * @return static
     */
    public function append(string $host): self
    {
        $labels = array_merge($this->filterComponent($host), $this->data);
        if ($this->data === $labels) {
            return $this;
        }

        $new = self::createFromLabels($labels, $this->is_absolute, $this->resolver);
        if (!empty($this->hostname)) {
            $new->lazyloadInfo();
        }

        return $new;
    }

    /**
     * Filter the component to append or prepend
     *
     * @param string $component
     *
     * @return array
     */
    protected function filterComponent(string $component): array
    {
        $component = $this->validateString($component);
        if ('' === $component) {
            return [];
        }

        if ('.' !== $component && '.' == substr($component, -1)) {
            $component = substr($component, 0, -1);
        }

        return $this->normalizeLabels($component);
    }

    /**
     * Returns an instance with the modified label
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the modified component with the replaced data
     *
     * @param int    $offset the label offset to remove and replace by the given component
     * @param string $host   the component added
     *
     * @return static
     */
    public function replaceLabel(int $offset, string $host): self
    {
        $labels = $this->replace($offset, $host);
        if ($labels === $this->data) {
            return $this;
        }

        $new = self::createFromLabels($labels, $this->is_absolute, $this->resolver);
        if (!empty($this->hostname)) {
            $new->lazyloadInfo();
        }

        return $new;
    }

    /**
     * Returns an instance without the specified keys
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the modified component
     *
     * @param int[] $offsets the list of keys to remove from the collection
     *
     * @return static
     */
    public function withoutLabels(array $offsets): self
    {
        $data = $this->delete($offsets);
        if ($data === $this->data) {
            return $this;
        }

        $new = self::createFromLabels($data, $this->is_absolute, $this->resolver);
        if (!empty($this->hostname)) {
            $new->lazyloadInfo();
        }

        return $new;
    }

    /**
     * Returns an instance with the specified registerable domain added
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the modified component with the new registerable domain
     *
     * @param string $host the registerable domain to add
     *
     * @return static
     */
    public function withPublicSuffix(string $host): self
    {
        if ('' === $host) {
            $host = null;
        }

        $source = $this->getContent();
        if ('' == $source) {
            return new static($host, $this->resolver);
        }

        $public_suffix = $this->getPublicSuffix();
        $new = $this->normalizeLabels($host);
        if (implode('.', array_reverse($new)) === $public_suffix) {
            return $this;
        }

        $offset = 0;
        if ('' != $public_suffix) {
            $offset = count(explode('.', $public_suffix));
        }

        $new = self::createFromLabels(
            array_merge($new, array_slice($this->data, $offset)),
            $this->is_absolute,
            $this->resolver
        );

        $new->lazyloadInfo();

        return $new;
    }

    /**
     * validate the submitted data
     *
     * @param string|null $host
     *
     * @throws Exception If the host is invalid
     *
     * @return array
     */
    protected function normalizeLabels(string $host = null): array
    {
        if (null === $host) {
            return [];
        }

        if ('' === $host) {
            return [''];
        }

        if ('.' === $host[0] || '.' === substr($host, -1)) {
            throw new Exception(sprintf('The submitted host `%s` is invalid', $host));
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [$host];
        }

        if ($this->isValidIpv6Hostname($host)) {
            return [$host];
        }

        if ($this->isValidIpFuture($host)) {
            return [$host];
        }

        $reg_name = strtolower(rawurldecode($host));

        if ($this->isValidDomain($reg_name)) {
            if (false !== strpos($reg_name, 'xn--')) {
                $reg_name = idn_to_utf8($reg_name, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            }

            return array_reverse(explode('.', $reg_name));
        }

        if ($this->isValidRegisteredName($reg_name)) {
            return [$reg_name];
        }

        throw new Exception(sprintf('The submitted host `%s` is invalid', $host));
    }

    /**
     * Returns an instance with the specified registerable domain added
     *
     * DEPRECATION WARNING! This method will be removed in the next major point release
     *
     * @deprecated 1.5.0 Typo fix in name
     * @see        Host::withRegistrableDomain
     *
     * @param string $host the registerable domain to add
     *
     * @return static
     */
    public function withRegisterableDomain(string $host): self
    {
        return $this->withRegistrableDomain($host);
    }

    /**
     * Returns an instance with the specified registerable domain added
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the modified component with the new registerable domain
     *
     * @param string $host the registerable domain to add
     *
     * @return static
     */
    public function withRegistrableDomain(string $host): self
    {
        if ('' === $host) {
            $host = null;
        }

        $source = $this->getContent();
        if ('' == $source) {
            return new static($host, $this->resolver);
        }

        $registerable_domain = $this->getRegistrableDomain();
        $new = $this->normalizeLabels($host);
        if (implode('.', array_reverse($new)) === $registerable_domain) {
            return $this;
        }

        $offset = 0;
        if ('' != $registerable_domain) {
            $offset = count(explode('.', $registerable_domain));
        }

        $new = self::createFromLabels(
            array_merge($new, array_slice($this->data, $offset)),
            $this->is_absolute,
            $this->resolver
        );
        $new->lazyloadInfo();

        return $new;
    }

    /**
     * Returns an instance with the specified sub domain added
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the modified component with the new sud domain
     *
     * @param string $host the subdomain to add
     *
     * @return static
     */
    public function withSubDomain(string $host): self
    {
        if ('' === $host) {
            $host = null;
        }

        $source = $this->getContent();
        if ('' == $source) {
            return new static($host, $this->resolver);
        }

        $subdomain = $this->getSubDomain();
        $new = $this->normalizeLabels($host);
        if (implode('.', array_reverse($new)) === $subdomain) {
            return $this;
        }

        $offset = count($this->data);
        if ('' != $subdomain) {
            $offset -= count(explode('.', $subdomain));
        }

        $new = self::createFromLabels(
            array_merge(array_slice($this->data, 0, $offset), $new),
            $this->is_absolute,
            $this->resolver
        );
        $new->lazyloadInfo();

        return $new;
    }

    /**
     * Returns an instance with a different domain resolver
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains a different domain resolver, and update the
     * host domain information.
     *
     * @param Rules|null $resolver
     *
     * @return static
     */
    public function withDomainResolver(Rules $resolver = null): self
    {
        if ($resolver == $this->resolver) {
            return $this;
        }

        $clone = clone $this;
        $clone->resolver = $resolver;
        if (!empty($this->hostname)) {
            $clone->lazyloadInfo();
        }

        return $clone;
    }
}
