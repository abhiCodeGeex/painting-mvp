<?php

namespace App\Support;

/**
 * Format checks for Indian identifiers (format only, not a government lookup).
 */
final class IndianIds
{
    /** 15 chars: state code, PAN, entity number, "Z", checksum. e.g. 27AAPFU0939F1ZV */
    public const GSTIN_REGEX = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    /** e.g. AAPFU0939F */
    public const PAN_REGEX = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    /** 6 digits, first not zero. */
    public const PIN_REGEX = '/^[1-9][0-9]{5}$/';

    /** Optional +91, then a 10-digit mobile/landline (spaces and dashes allowed). */
    public const PHONE_REGEX = '/^(\+?\d{1,3}[\s-]?)?\d[\d\s-]{7,13}\d$/';
}
