<?php
// ======================================================
// [CONFIG] Master Department & Section List
// ======================================================
return [
    // SQP & Admin have real sub-sections
    "SQP"     => ["SAFETY QUALITY PLANNING", "SAFETY", "QA", "PLANNING", "IT"], 
    "ADMIN"   => ["ADMIN","GAG", "TKG", "PCG", "ACG", "MED", "OP", "CLEANERS/HOUSE KEEPING"],
    
    // OPERATIONS - Combined into single official names
    "SIGCOM"  => ["SIGNALING & COMMUNICATION"], 
    "PSS"     => ["POWER SUPPLY SECTION"],
    "OCS"     => ["OVERHEAD CATENARY SYSTEM"],
    
    // MAINTENANCE
    "HMS"     => ["HEAVY MAINTENANCE SECTION"],
    "RAS"     => ["ROOT CAUSE ANALYSIS "],
    "TRS"     => ["TECHNICAL RESEARCH SECTION"],
    "LMS"     => ["LIGHT MAINTENANCE SECTION"],
    "DOS"     => ["DEPARTMENT OPERATIONS SECTION"],
    
    // FACILITIES
    "CTS"     => ["CIVIL TRACKS SECTION"],
    "BFS"     => ["BUILDING FACILITIES SECTION"],
    "WHS"     => ["WAREHOUSE SECTION"],
    "GUNJIN"  => ["EMT", "SECURITY"],
    
    // OTHERS
    "SUBCONS-OTHERS" => ["OTHERS"],

    // [NEW] Add your new department here:
    // "MARKETING" => ["SALES", "SOCIAL MEDIA", "EVENTS"],
];
