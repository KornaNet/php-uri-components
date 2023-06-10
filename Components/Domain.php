<?php

/**
 * League.Uri (https://uri.thephpleague.com)
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Uri\Components;

use Iterator;
use League\Uri\Contracts\AuthorityInterface;
use League\Uri\Contracts\DomainHostInterface;
use League\Uri\Contracts\HostInterface;
use League\Uri\Contracts\UriComponentInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Exceptions\OffsetOutOfBounds;
use League\Uri\Exceptions\SyntaxError;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use Stringable;
use TypeError;
use function array_count_values;
use function array_filter;
use function array_keys;
use function array_reverse;
use function array_shift;
use function count;
use function explode;
use function implode;
use function sprintf;

final class Domain extends Component implements DomainHostInterface
{
    private const SEPARATOR = '.';

    private readonly HostInterface $host;
    /** @var string[] */
    private readonly array $labels;

    private function __construct(UriComponentInterface|Stringable|int|string $host)
    {
        $host = match (true) {
            $host instanceof HostInterface => $host,
            $host instanceof UriComponentInterface => Host::createFromString($host->value()),
            default => Host::createFromString((string) $host),
        };

        if (!$host->isDomain()) {
            throw new SyntaxError(sprintf('`%s` is an invalid domain name.', $host->value() ?? 'null'));
        }

        $this->host = $host;
        $this->labels = array_reverse(explode(self::SEPARATOR, $this->host->value() ?? ''));
    }

    /**
     * Returns a new instance from a string or a stringable object.
     */
    public static function createFromString(Stringable|string $host): self
    {
        return self::createFromHost(Host::createFromString($host));
    }

    /**
     * Returns a new instance from an iterable structure.
     *
     * @throws TypeError If a label is the null value
     */
    public static function createFromLabels(iterable $labels): self
    {
        $hostLabels = [];
        foreach ($labels as $label) {
            $label = self::filterComponent($label);
            if (null === $label) {
                throw new TypeError('A label can not be null.');
            }
            $hostLabels[] = $label;
        }

        return self::createFromString(implode(self::SEPARATOR, array_reverse($hostLabels)));
    }

    /**
     * Create a new instance from a URI object.
     */
    public static function createFromUri(Psr7UriInterface|UriInterface $uri): self
    {
        return self::createFromHost(Host::createFromUri($uri));
    }

    /**
     * Create a new instance from an Authority object.
     */
    public static function createFromAuthority(AuthorityInterface $authority): self
    {
        return self::createFromHost(Host::createFromAuthority($authority));
    }

    /**
     * Returns a new instance from an iterable structure.
     */
    public static function createFromHost(HostInterface $host): self
    {
        return new self($host);
    }

    public static function new(): self
    {
        return new self(Host::new());
    }

    public function value(): ?string
    {
        return $this->host->value();
    }

    public function getUriComponent(): string
    {
        return (string) $this->value();
    }

    public function toAscii(): ?string
    {
        return $this->host->toAscii();
    }

    public function toUnicode(): ?string
    {
        return $this->host->toUnicode();
    }

    public function isIp(): bool
    {
        return false;
    }

    public function isDomain(): bool
    {
        return true;
    }

    public function getIpVersion(): ?string
    {
        return null;
    }

    public function getIp(): ?string
    {
        return null;
    }

    public function count(): int
    {
        return count($this->labels);
    }

    public function getIterator(): Iterator
    {
        yield from $this->labels;
    }

    public function get(int $offset): ?string
    {
        if ($offset < 0) {
            $offset += count($this->labels);
        }

        return $this->labels[$offset] ?? null;
    }

    public function keys(?string $label = null): array
    {
        if (null === $label) {
            return array_keys($this->labels);
        }

        return array_keys($this->labels, $label, true);
    }

    public function labels(): array
    {
        return $this->labels;
    }

    public function isAbsolute(): bool
    {
        return count($this->labels) > 1 && '' === $this->labels[array_key_first($this->labels)];
    }

    /**
     * @param UriComponentInterface|HostInterface|Stringable|int|string|null $label
     */
    public function prepend($label): DomainHostInterface
    {
        $label = self::filterComponent($label);
        if (null === $label) {
            return $this;
        }

        return new self($label.self::SEPARATOR.$this->value());
    }

    /**
     * @param UriComponentInterface|HostInterface|Stringable|int|string|null $label
     */
    public function append($label): DomainHostInterface
    {
        $label = self::filterComponent($label);
        if (null === $label) {
            return $this;
        }

        return new self($this->value().self::SEPARATOR.$label);
    }

    public function withRootLabel(): DomainHostInterface
    {
        $key = array_key_first($this->labels);
        if ('' === $this->labels[$key]) {
            return $this;
        }

        return $this->append('');
    }

    public function withoutRootLabel(): DomainHostInterface
    {
        $key = array_key_first($this->labels);
        if ('' !== $this->labels[$key]) {
            return $this;
        }

        $labels = $this->labels;
        array_shift($labels);

        return self::createFromLabels($labels);
    }

    /**
     * @throws OffsetOutOfBounds
     */
    public function withLabel(int $key, UriComponentInterface|HostInterface|Stringable|int|string|null $label): DomainHostInterface
    {
        $nb_labels = count($this->labels);
        if ($key < - $nb_labels - 1 || $key > $nb_labels) {
            throw new OffsetOutOfBounds(sprintf('No label can be added with the submitted key : `%s`.', $key));
        }

        if (0 > $key) {
            $key += $nb_labels;
        }

        if ($nb_labels === $key) {
            return $this->append($label);
        }

        if (-1 === $key) {
            return $this->prepend($label);
        }

        if (!$label instanceof HostInterface) {
            $label = null === $label ? Host::new() : Host::createFromString((string) $label);
        }

        $label = $label->value();
        if ($label === $this->labels[$key]) {
            return $this;
        }

        $labels = $this->labels;
        $labels[$key] = $label;

        return new self(implode(self::SEPARATOR, array_reverse($labels)));
    }

    public function withoutLabel(int ...$keys): DomainHostInterface
    {
        if ([] === $keys) {
            return $this;
        }

        $nb_labels = count($this->labels);
        foreach ($keys as &$offset) {
            if (- $nb_labels > $offset || $nb_labels - 1 < $offset) {
                throw new OffsetOutOfBounds(sprintf('No label can be removed with the submitted key : `%s`.', $offset));
            }

            if (0 > $offset) {
                $offset += $nb_labels;
            }
        }
        unset($offset);

        $deleted_keys = array_keys(array_count_values($keys));
        $filter = static fn ($key): bool => !in_array($key, $deleted_keys, true);

        return self::createFromLabels(array_filter($this->labels, $filter, ARRAY_FILTER_USE_KEY));
    }

    public function clear(): self
    {
        return new self(Host::new());
    }

    public function slice(int $offset, int $length = null): self
    {
        $nbLabels = count($this->labels);
        if ($offset < - $nbLabels || $offset > $nbLabels) {
            throw new OffsetOutOfBounds(sprintf('No label can be removed with the submitted key : `%s`.', $offset));
        }

        $labels = array_slice($this->labels, $offset, $length, true);
        if ($labels === $this->labels) {
            return $this;
        }

        return new self([] === $labels ? Host::new() : Host::createFromString(implode('.', array_reverse($labels))));
    }
}
