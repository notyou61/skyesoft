<!-- Doctype HTML Tag -->
<!DOCTYPE html>
<!-- HTML Tag -->
<html lang="en">
	<!-- Head Tags -->
	<head>
		<!-- Meta Tags -->
		<meta charset="UTF-8"> <!-- Set character encoding -->
		<meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- Enable responsive scaling -->
		<!-- Page Title -->
		<title>Slide Controller</title> 
		<!-- Styles Tag -->
		<style>
			/* Body Styles */
			body {
				font-family: Arial, sans-serif; /* Use sans-serif font */
				text-align: center; /* Center align text */
				padding: 20px; /* Add padding around the body */
			}
			/* Button Styles */
			button {
				padding: 10px 20px; /* Add padding inside buttons */
				font-size: 1rem; /* Set font size */
				margin: 10px; /* Add margin around buttons */
				cursor: pointer; /* Change cursor to pointer */
			}
			/* Error Message Styles */
			#errorMessage {
				margin-top: 20px; /* Add space above the error message */
				color: red; /* Make text red for visibility */
				font-weight: bold; /* Use bold text */
			}
		</style>
	</head>
	<!-- Body -->
	<body>
		<!-- Page Header -->
		<h1>Slide Controller</h1> <!-- Title of the page -->
		<!-- Current Slide Information -->
		<p id="currentSlideInfo">Loading slide information...</p> <!-- Placeholder for slide info -->
		<!-- Navigation Buttons -->
		<button id="prevSlide" onclick="navigateSlide('prev')">Previous Slide</button> <!-- Navigate to the previous slide -->
		<button id="nextSlide" onclick="navigateSlide('next')">Next Slide</button> <!-- Navigate to the next slide -->
		<!-- Error Message Div -->
		<div id="errorMessage"></div> <!-- Div to display error or success messages --
		<!-- Script -->
		<script>
			// Initialize Variables
			let currentSlideIndex = 0; // Current slide index, starts at 0
			let slides = []; // Array to hold slides
			// Fetch Slides from the Server
			fetch('presentationData/slides.json') // Path to the slides JSON file
				.then(response => {
					// Check if the response is OK
					if (!response.ok) {
						throw new Error(`HTTP error! status: ${response.status}`); // Throw error for bad response
					}
					return response.json(); // Parse the response JSON
				})
				.then(data => {
					// Check if the slides array exists and is valid
					if (data.slides && Array.isArray(data.slides)) {
						slides = data.slides; // Assign slides to the array
						updateSlideOnServer(); // Display the first slide
					} else {
						console.error("Invalid slides JSON structure."); // Log an error for invalid JSON
					}
				})
				.catch(error => {
					// Call Display Message Function
					displayMessage(`Error loading slides: ${error.message}`); // Display fetch error
				});
			// Navigate Slide Function
			function navigateSlide(direction) {
				// Check if navigating to the next slide is valid
				if (direction === 'next' && currentSlideIndex < slides.length - 1) {
					currentSlideIndex++; // Move to the next slide
				} 
				// Check if navigating to the previous slide is valid
				else if (direction === 'prev' && currentSlideIndex > 0) {
					currentSlideIndex--; // Move to the previous slide
				}
				// Call Update Slide On Server Function
				updateSlideOnServer(); // Update the current slide on the server
			}
			// Update Slide on Server Function
			function updateSlideOnServer() {
				// Check if the current slide exists
				if (slides[currentSlideIndex]) {
					// Send the current slide to the server
					fetch('/presentationPages/presentationControl.php', { // Server endpoint for updates
						method: 'POST', // Use POST method to send data
						headers: {
							'Content-Type': 'application/json' // Send JSON data
						},
						body: JSON.stringify({
							currentSlide: slides[currentSlideIndex] // Send current slide data
						})
					})
						.then(response => response.text()) // Parse the response text
						.then(data => {
							// Console Log (Commented Out)
							//console.log("Slide updated:", data); // Log the server response
							//
							const slideInfo = document.getElementById("currentSlideInfo"); // Get the slide info element
							if (slideInfo) {
								slideInfo.textContent = 
									`Slide ${currentSlideIndex + 1}/${slides.length}: ${slides[currentSlideIndex].title}`; // Update slide info
							}
							updateButtonState(); // Update button states (enable/disable)
						})
						.catch(error => {
							// Call Display Message Function
							displayMessage(`Error updating slide: ${error.message}`); // Display errors during update
						});
				}
			}
			// Update Button State Function
			function updateButtonState() {
				// Get the previous slide button
				const prevButton = document.getElementById("prevSlide");
				// Get the next slide button
				const nextButton = document.getElementById("nextSlide");
				// Disable the previous button if on the first slide
				if (prevButton) prevButton.disabled = currentSlideIndex === 0;
				// Disable the next button if on the last slide
				if (nextButton) nextButton.disabled = currentSlideIndex === slides.length - 1;
			}
			// Display Message Function
			function displayMessage(message) {
				// Assign Error Message Div
				const errorMessageDiv = document.getElementById("errorMessage"); // Get the error message div
				// Error message Div Conditional
				if (errorMessageDiv) {
					// Assign Error Message Div
					errorMessageDiv.textContent = message; // Set the message text
				}
			}
		</script>
		<!-- PHP Code -->
		<?php
			// Post Conditional
			if ($_SERVER['REQUEST_METHOD'] === 'POST') {
				// Paths to files
				$updatableFilePath = '../textFiles/updatableDatabaseArray.txt'; // Path to the stream file
				// Get the raw POST data
				$inputData = file_get_contents('php://input'); // Retrieve input data
				// Assign Request Data
				$requestData = json_decode($inputData, true); // Decode JSON input data
				// Validate and update the presentation array
				if (isset($requestData['currentSlide']) && is_array($requestData['currentSlide'])) {
					// Load the existing updatableDatabaseArray
					$existingData = json_decode(file_get_contents($updatableFilePath), true); // Decode the current file contents
					// Assign Existing Data
					$existingData['presentationArray']['currentSlide'] = $requestData['currentSlide']; // Update the current slide
					// Write the updated data back to the file
					file_put_contents($updatableFilePath, json_encode($existingData, JSON_PRETTY_PRINT)); // Save changes to the file
					// Echo (Slide Updated Successfully)
					echo "Slide Updated Successfully."; // Confirm the update
				} else {
					// Echo (Invalid Slide Data)
					echo "Invalid Slide Data."; // Log invalid slide data
				}
			} else {
				// Echo (Ready For Updates)
				echo "Ready For Updates."; // Message for GET requests
			}
		?>
	</body>
</html>