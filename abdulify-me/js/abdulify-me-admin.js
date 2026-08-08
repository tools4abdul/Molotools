/**
 * Abdulify Me Admin UI
 * Handles overlay management functionality in the admin settings page
 */

(function() {
	'use strict';

	// Ensure API exists
	if (typeof abdulifyMeAdmin === 'undefined') {
		return;
	}

	// Wait for DOM to be ready
	document.addEventListener('DOMContentLoaded', function() {
		initializeOverlayManagement();
	});

	/**
	 * Initialize all overlay management functionality
	 */
	function initializeOverlayManagement() {
		const uploadForm = document.getElementById('am-overlay-upload-form');
		const renameButtons = document.querySelectorAll('.am-overlay-rename-btn');
		const deleteButtons = document.querySelectorAll('.am-overlay-delete-btn');

		if (uploadForm) {
			uploadForm.addEventListener('submit', handleUpload);
		}

		renameButtons.forEach(button => {
			button.addEventListener('click', handleRename);
		});

		deleteButtons.forEach(button => {
			button.addEventListener('click', handleDelete);
		});
	}

	function handleUpload(event) { /* omitted for brevity */ }

})();
