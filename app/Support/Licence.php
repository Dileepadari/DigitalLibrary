<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The basis on which a file may be here. Required on every record: an operator
 * has to be able to answer "why is this on your site?" for anything on it.
 * See PLAN.md section 9.
 */
enum Licence: string
{
    case PublicDomain = 'public_domain';
    case CcBy = 'cc_by';
    case CcBySa = 'cc_by_sa';
    case CcOther = 'cc_other';
    case AuthorPermission = 'author_permission';
    case OwnWork = 'own_work';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::PublicDomain     => 'Public domain',
            self::CcBy             => 'Creative Commons BY',
            self::CcBySa           => 'Creative Commons BY-SA',
            self::CcOther          => 'Creative Commons, other',
            self::AuthorPermission => 'With the author\'s permission',
            self::OwnWork          => 'The uploader\'s own work',
            self::Unknown          => 'Not established',
        };
    }

    /**
     * Whether this basis needs a human to look at it before the record goes
     * public. Reviewers see the note the submitter wrote.
     */
    public function needsEvidence(): bool
    {
        return match ($this) {
            self::PublicDomain, self::CcBy, self::CcBySa => false,
            default                                      => true,
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
