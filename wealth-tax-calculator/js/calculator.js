// Billionaire Wealth Tax Calculator — WordPress plugin script
// Element IDs are prefixed with "wtc-" to avoid collisions with other page content.

(function () {
    'use strict';

    // ── Constants ──────────────────────────────────────────────────────────────
    // Data is injected from WordPress via wp_localize_script
    var FIVE_PERCENT_BASELINE_REVENUE = 4.4e12;

    // ── State ──────────────────────────────────────────────────────────────────
    var comparisonsData = (typeof wealthTaxConfig !== 'undefined' && wealthTaxConfig.comparisons)
        ? wealthTaxConfig.comparisons
        : [];
    var POLICY_GROUP_KEYS = ['healthcare', 'education', 'business', 'directRelief', 'housing', 'childcare'];
    var selectedPolicies = POLICY_GROUP_KEYS.slice();
    var selectedPolicyOptions = {};
    var selectedPoliciesOrder = [];
    var dragPolicyKey = null;
    var MOBILE_LONG_PRESS_MS = 450;
    var mobileLongPressTimer = null;
    var mobileLongPressEntryKey = null;
    var mobileLongPressPointerId = null;
    var mobileLongPressStartX = 0;
    var mobileLongPressStartY = 0;
    var mobileDragReady = false;
    var collapsedPolicyGroups = [];
    var TAX_RATE_MIN = 1;
    var TAX_RATE_MAX = 10;
    var sliderController = {
        instance: null,
        suppressCallback: false,
        resizeTicking: false
    };
    var summarySliderController = {
        instance: null,
        suppressCallback: false,
        resizeTicking: false
    };
    var moneyPileController = {
        stages: {}
    };
    var analyticsController = {
        enabled: false,
        endpoint: '',
        nonce: '',
        sessionId: ''
    };
    var requestFrame = window.requestAnimationFrame || function (callback) { return window.setTimeout(callback, 16); };
    var supportsCssVariables = !!(window.CSS && window.CSS.supports && window.CSS.supports('--wtc-test', '0'));
    var MONEY_PILE_PROFILES = [0.26, 0.4, 0.58, 0.82, 1, 0.92, 0.72, 0.5, 0.34];
    var MONEY_PILE_MAX_BUNDLES = 14;
    var MONEY_PILE_STAGE_CONFIGS = [
        {
            key: 'main',
            shellSelector: '.wtc-slider-shell',
            fieldId: 'wtc-moneyField'
        },
        {
            key: 'summary',
            shellSelector: '.wtc-fs-slider-shell',
            fieldId: 'wtc-fs-moneyField'
        }
    ];

    // Policy category labels
    var POLICY_LABELS = {
        healthcare: 'Healthcare',
        education: 'Education',
        business: 'Tax Relief',
        directRelief: 'Direct Relief',
        housing: 'Housing',
        childcare: 'Childcare & Families'
    };

    var POLICY_FILL_COLORS = {
        healthcare:   '#D1495B',
        education:    '#2B59C3',
        business:     '#2A9D8F',
        directRelief: '#F4A261',
        housing:      '#7B2CBF',
        childcare:    '#3A86FF'
    };

})();
