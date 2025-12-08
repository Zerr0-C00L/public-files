<?php
/**
 * Fetch all TMDB movies that belong to collections
 * Saves to collections_playlist.json
 */

$GLOBALS['DEBUG'] = false;
set_time_limit(0);

if (!$GLOBALS['DEBUG']) {
    error_reporting(0);
}

$apiKey = getenv('SECRET_API_KEY');
$playVodUrl = "[[SERVER_URL]]/play.php";
$language = 'en-US';

$outputData = [];
$addedMovieIds = [];
$collectionsFetched = [];

echo "Starting TMDB Collections Fetch...\n";

// First, get popular collections by fetching popular movies and extracting their collection IDs
$collectionIds = [];

// Fetch from multiple sources to get a wide variety of collections
$sources = [
    'popular' => 'https://api.themoviedb.org/3/movie/popular',
    'top_rated' => 'https://api.themoviedb.org/3/movie/top_rated',
    'now_playing' => 'https://api.themoviedb.org/3/movie/now_playing'
];

// Also search for known big franchises
$knownCollections = [
    10, // Star Wars
    86311, // Avengers
    131295, // Spider-Man (MCU)
    131296, // Iron Man
    131292, // Hulk
    86066, // Despicable Me
    8945, // Ice Age
    33514, // Harry Potter
    328, // Jurassic Park
    9485, // Fast & Furious
    119, // Lord of the Rings
    87359, // Mission: Impossible
    173710, // Planet of the Apes
    87096, // Transformers
    2150, // Shrek
    137697, // Finding Nemo
    468552, // Wonder Woman
    209131, // John Wick
    748, // X-Men
    573436, // Spider-Man (Sony)
    230, // The Godfather
    735, // Die Hard
    529892, // Godzilla (Monsterverse)
    84, // Indiana Jones
    295, // Pirates of the Caribbean
    1241, // Harry Potter
    121938, // Hobbit
    304, // Ocean's
    8650, // Bourne
    1733, // Mummy
    2806, // American Pie
    31562, // Paranormal Activity
    8091, // Alien
    115762, // Kung Fu Panda
    89137, // Madagascar
    9743, // Hangover
    133352, // Resident Evil
    422837, // Venom
    386382, // Toy Story
    404609, // Deadpool
    263, // Dark Knight
    726871, // Dune
    448150, // Sonic
    131634, // Hunger Games
    645, // James Bond
    528, // Terminator
    1570, // Rocky/Creed
    399, // Rambo
    2467, // Underworld
    950390, // Top Gun
    9735, // Saw
    656, // Final Destination
    2326, // Blade
    495764, // Birds of Prey
    424559, // Insidious
    665374, // Conjuring
    1030954, // Knives Out
    948485, // Bad Boys
];

// Add known collections first
foreach ($knownCollections as $collId) {
    $collectionIds[$collId] = true;
}

echo "Added " . count($knownCollections) . " known collection IDs\n";

// Fetch movies from different sources to discover more collections
foreach ($sources as $sourceName => $baseUrl) {
    echo "Scanning $sourceName for collections...\n";
    
    for ($page = 1; $page <= 50; $page++) {
        $url = "$baseUrl?api_key=$apiKey&language=$language&page=$page";
        $response = @file_get_contents($url);
        
        if ($response === false) continue;
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['results'])) continue;
        
        foreach ($data['results'] as $movie) {
            if (!isset($movie['id'])) continue;
            
            // Get movie details to check for collection
            $detailUrl = "https://api.themoviedb.org/3/movie/{$movie['id']}?api_key=$apiKey&language=$language";
            $detailResponse = @file_get_contents($detailUrl);
            
            if ($detailResponse === false) continue;
            
            $detail = json_decode($detailResponse, true);
            if ($detail && isset($detail['belongs_to_collection']['id'])) {
                $collectionIds[$detail['belongs_to_collection']['id']] = true;
            }
            
            usleep(25000); // Rate limiting - 40 requests per second max
        }
        
        usleep(50000);
    }
}

echo "Found " . count($collectionIds) . " unique collections\n";

// Now fetch all movies from each collection
$collectionCount = 0;
foreach (array_keys($collectionIds) as $collectionId) {
    $url = "https://api.themoviedb.org/3/collection/$collectionId?api_key=$apiKey&language=$language";
    $response = @file_get_contents($url);
    
    if ($response === false) {
        usleep(100000);
        continue;
    }
    
    $collection = json_decode($response, true);
    if (!$collection || !isset($collection['parts'])) continue;
    
    $collectionName = $collection['name'] ?? 'Unknown Collection';
    $collectionCount++;
    
    echo "[$collectionCount] Fetching: $collectionName (" . count($collection['parts']) . " movies)\n";
    
    foreach ($collection['parts'] as $movie) {
        if (isset($addedMovieIds[$movie['id']])) continue;
        
        // Skip unreleased movies
        if (isset($movie['release_date']) && !empty($movie['release_date'])) {
            $releaseYear = (int)substr($movie['release_date'], 0, 4);
            if ($releaseYear > (int)date('Y')) continue;
            if (strtotime($movie['release_date']) > time()) continue;
        } else {
            continue; // Skip movies without release date
        }
        
        // Skip movies without poster
        if (empty($movie['poster_path'])) continue;
        
        $addedMovieIds[$movie['id']] = true;
        
        $outputData[] = [
            'num' => count($outputData) + 1,
            'name' => $movie['title'] ?? 'Unknown',
            'stream_type' => 'movie',
            'stream_id' => $movie['id'],
            'stream_icon' => 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'],
            'rating' => $movie['vote_average'] ?? 0,
            'added' => time(),
            'category_id' => '999994', // Collections category
            'category_name' => 'Collections',
            'collection_id' => $collectionId,
            'collection_name' => $collectionName,
            'container_extension' => 'mp4',
            'custom_sid' => '',
            'direct_source' => $playVodUrl . '?id=' . $movie['id'] . '&type=movie'
        ];
    }
    
    usleep(50000); // Rate limiting
}

echo "\nTotal: " . count($outputData) . " movies from $collectionCount collections\n";

// Save to JSON
file_put_contents('collections_playlist.json', json_encode($outputData, JSON_PRETTY_PRINT));
echo "Saved to collections_playlist.json\n";
