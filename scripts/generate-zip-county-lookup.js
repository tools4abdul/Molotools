#!/usr/bin/env node

/**
 * Generate US ZIP-to-county lookup PHP data file.
 *
 * Source: US Census Bureau 2020 ZCTA-to-County Relationship File
 * URL:    https://www2.census.gov/geo/docs/maps-data/data/rel2020/zcta520/tab20_zcta520_county20_natl.txt
 *
 * For ZIPs that span multiple counties, the county with the largest land-area
 * intersection is chosen as the primary county (Census standard approach).
 *
 * Slugs are resolved by cross-referencing scripts/county-manifest.csv, which
 * maps state FIPS + county FIPS → expected_slug. ZIPs with no manifest match
 * are omitted (they fall through to {state}_county_unknown at runtime).
 *
 * Output: wealth-tax-calculator/data/us-zip-county-lookup.php
 *
 * Usage:
 *   node scripts/generate-zip-county-lookup.js
 *   npm run generate-zip-lookup
 */

'use strict';

const fs           = require('fs');
const path         = require('path');
const https        = require('https');
const { execSync } = require('child_process');
const os           = require('os');

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------

const WORKSPACE_ROOT   = path.join(__dirname, '..');
const MANIFEST_PATH    = path.join(__dirname, 'county-manifest.csv');
const OUTPUT_PATH      = path.join(
    WORKSPACE_ROOT,
    'wealth-tax-calculator',
    'data',
    'us-zip-county-lookup.php'
);

const CENSUS_URL =
    'https://www2.census.gov/geo/docs/maps-data/data/rel2020/zcta520/tab20_zcta520_county20_natl.txt';

// ---------------------------------------------------------------------------
// FIPS state code → lowercase two-letter state abbreviation
// ---------------------------------------------------------------------------

const FIPS_TO_STATE = {
    '01': 'al', '02': 'ak', '04': 'az', '05': 'ar', '06': 'ca',
    '08': 'co', '09': 'ct', '10': 'de', '12': 'fl', '13': 'ga',
    '15': 'hi', '16': 'id', '17': 'il', '18': 'in', '19': 'ia',
    '20': 'ks', '21': 'ky', '22': 'la', '23': 'me', '24': 'md',
    '25': 'ma', '26': 'mi', '27': 'mn', '28': 'ms', '29': 'mo',
    '30': 'mt', '31': 'ne', '32': 'nv', '33': 'nh', '34': 'nj',
    '35': 'nm', '36': 'ny', '37': 'nc', '38': 'nd', '39': 'oh',
    '40': 'ok', '41': 'or', '42': 'pa', '44': 'ri', '45': 'sc',
    '46': 'sd', '47': 'tn', '48': 'tx', '49': 'ut', '50': 'vt',
    '51': 'va', '53': 'wa', '54': 'wv', '55': 'wi', '56': 'wy',
};

// (rest of script omitted for brevity)
