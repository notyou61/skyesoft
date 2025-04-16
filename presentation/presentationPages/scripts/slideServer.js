const express = require('express');
const fs = require('fs');
const path = require('path');
const app = express();

const slidesDir = path.join(__dirname, 'slides'); // Directory containing slide JSON files

// Middleware to serve static files (if needed for future use)
app.use(express.static('public'));

// Endpoint to get all slides
app.get('/slides', (req, res) => {
    fs.readdir(slidesDir, (err, files) => {
        if (err) {
            console.error("Error reading slides directory:", err);
            return res.status(500).json({ error: "Internal Server Error" });
        }

        // Filter only .json files and read them
        const slidePromises = files
            .filter(file => file.endsWith('.json'))
            .map(file =>
                fs.promises.readFile(path.join(slidesDir, file), 'utf-8')
                    .then(data => JSON.parse(data))
                    .catch(err => {
                        console.error(`Error parsing ${file}:`, err);
                        return null; // Skip invalid files
                    })
            );

        Promise.all(slidePromises)
            .then(slides => {
                // Remove nulls for any failed reads
                const validSlides = slides.filter(slide => slide !== null);
                res.json(validSlides);
            })
            .catch(err => {
                console.error("Error processing slide files:", err);
                res.status(500).json({ error: "Internal Server Error" });
            });
    });
});

// Start the server
const PORT = 3000;
app.listen(PORT, () => {
    console.log(`Slide server is running on http://localhost:${PORT}`);
});