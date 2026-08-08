<?php
/**
 * US Census CBSA (Core Based Statistical Area) City Classification Mapping
 * 
 * Maps US cities to urban/rural classifications based on Census Bureau CBSA definitions:
 * - urban: Metropolitan Statistical Area (population 50,000+)
 * - suburban: Micropolitan Statistical Area (population 10,000-50,000)
 * - rural: Non-metro areas (outside CBSA)
 * 
 * Data source: US Census Bureau CBSA delineation files
 * Last updated: 2024
 * 
 * Format: city_slug => array('classification' => 'urban|suburban|rural', 'cbsa_name' => '...', 'state' => 'XX')
 */

// Major Metropolitan Areas (urban)
$cbsa_mapping = array(
	// Michigan
	'detroit' => array('classification' => 'urban', 'cbsa_name' => 'Detroit-Warren-Dearborn', 'state' => 'MI'),
	'grand-rapids' => array('classification' => 'urban', 'cbsa_name' => 'Grand Rapids-Kentwood', 'state' => 'MI'),
	'ann-arbor' => array('classification' => 'urban', 'cbsa_name' => 'Ann Arbor', 'state' => 'MI'),
);
