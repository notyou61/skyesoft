<?php
// File path for updatableDatabaseArray
$updatableFilePath = '../textFiles/updatableDatabaseArray.txt';

// Read existing data
$retrievedJsonString = file_get_contents($updatableFilePath);
$updatableDatabaseArray = json_decode($retrievedJsonString, true);

// Get incoming data
$inputData = json_decode(file_get_contents('php://input'), true);

// Check and update selected slide ID
if (isset($inputData['presentationArray']['selectedSlideId'])) {
    //
	$updatableDatabaseArray['presentationArray']['selectedSlideId'] = $inputData['presentationArray']['selectedSlideId'];
}
// Save updated data back to file
file_put_contents($updatableFilePath, json_encode($updatableDatabaseArray));
// Respond to the controller
echo 'Slide selection updated successfully.';
?>
