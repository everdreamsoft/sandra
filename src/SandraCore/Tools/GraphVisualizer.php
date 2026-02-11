<?php

namespace SandraCore\Tools;

use SandraCore\CommonFunctions;
use SandraCore\Concept;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\System;

/**
 * GraphVisualizer provides tools to visualize Sandra Core data as interactive graphs
 * using D3.js visualization library.
 */
class GraphVisualizer
{
    /**
     * @var System
     */
    private $system;
    
    /**
     * @var array
     */
    private $config = [
        'nodeSize' => 10,
        'linkDistance' => 100,
        'charge' => -300,
        'width' => 1000,
        'height' => 800,
        'colors' => [
            'entity' => '#1f77b4',
            'concept' => '#ff7f0e',
            'reference' => '#2ca02c',
            'verb' => '#9467bd',
            'value' => '#8c564b'
        ],
        'maxNodes' => 500, // Limit to prevent browser performance issues
        'showDetails' => true // Show detailed information in panels
    ];
    
    /**
     * Constructor
     * 
     * @param System $system The Sandra Core system instance
     * @param array $config Optional configuration parameters
     */
    public function __construct(System $system, array $config = [])
    {
        $this->system = $system;
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * Process an entity and its relationships for visualization
     */
    private function processEntity(Entity $entity, &$nodes, &$links, &$nodeMap, &$nodeCount, $depth, $maxDepth, $includeReferences, $filterVerbs)
    {
        // Stop if we've reached max depth or nodes
        if ($depth > $maxDepth || $nodeCount >= $this->config['maxNodes']) {
            return;
        }
        
        // Create a unique ID for this entity
        $entityId = "e_" . $entity->entityId;
        
        // If we've already processed this entity, skip
        if (isset($nodeMap[$entityId])) {
            return;
        }
        
        // Add entity node
        $entityName = $this->getEntityLabel($entity);
        $entityType = $entity->subjectConcept->getShortname() ?: 'entity';
        
        // Add detailed information about the entity
        $entityDetails = [
            'conceptId' => $entity->subjectConcept->idConcept,
            'conceptName' => $entity->subjectConcept->getShortname(),
            'references' => []
        ];
        
        // Add the entity node
        $nodes[] = [
            'id' => $entityId,
            'name' => $entityName,
            'type' => 'entity',
            'group' => 1,
            'details' => $entityDetails,
            'entityType' => $entityType
        ];
        
        $nodeMap[$entityId] = $nodeCount++;
        
        // Process subject concept
        $conceptId = "t_" . $entity->subjectConcept->idConcept;
        if (!isset($nodeMap[$conceptId])) {
            $nodes[] = [
                'id' => $conceptId,
                'name' => $entity->subjectConcept->getShortname() ?: "Concept #" . $entity->subjectConcept->idConcept,
                'type' => 'concept',
                'group' => 3,
                'details' => [
                    'id' => $entity->subjectConcept->idConcept,
                    'shortname' => $entity->subjectConcept->getShortname()
                ]
            ];
            $nodeMap[$conceptId] = $nodeCount++;
        }
        
        // Add "is_a" link between entity and concept
        $isAVerbId = "v_is_a";
        if (!isset($nodeMap[$isAVerbId])) {
            $nodes[] = [
                'id' => $isAVerbId,
                'name' => 'is_a',
                'type' => 'verb',
                'group' => 2,
                'details' => ['description' => 'Entity type relationship']
            ];
            $nodeMap[$isAVerbId] = $nodeCount++;
        }
        
        $links[] = [
            'source' => $entityId,
            'target' => $isAVerbId,
            'value' => 1
        ];
        
        $links[] = [
            'source' => $isAVerbId,
            'target' => $conceptId,
            'value' => 1
        ];
        
        // Process references if enabled
        if ($includeReferences) {
            foreach ($entity->entityRefs as $refName => $refValues) {
                $refId = "r_" . md5($refName);
                
                // Add reference node if it doesn't exist
                if (!isset($nodeMap[$refId])) {
                    $nodes[] = [
                        'id' => $refId,
                        'name' => $refName,
                        'type' => 'reference',
                        'group' => 4,
                        'details' => ['description' => 'Entity reference']
                    ];
                    $nodeMap[$refId] = $nodeCount++;
                }
                
                // Link entity to reference
                $links[] = [
                    'source' => $entityId,
                    'target' => $refId,
                    'value' => 1
                ];
                
                // Add reference values
                foreach ($refValues as $refValue) {
                    $valueId = "rv_" . md5('df');
                    
                    // Add value node if it doesn't exist
                    if (!isset($nodeMap[$valueId])) {
                        $nodes[] = [
                            'id' => $valueId,
                            'name' => $refValue,
                            'type' => 'value',
                            'group' => 5,
                            'details' => ['reference' => $refName]
                        ];
                        $nodeMap[$valueId] = $nodeCount++;
                        
                        // Store reference in entity details
                        $entityDetails['references'][$refName] = $refValue;
                    }
                    
                    // Link reference to value
                    $links[] = [
                        'source' => $refId,
                        'target' => $valueId,
                        'value' => 1
                    ];
                }
            }
        }
        
        // Process triplets (entity -> verb -> target)
        foreach ($entity->subjectConcept->tripletArray as $verb => $targetConcepts) {
            // Skip filtered verbs
            if (!empty($filterVerbs) && !in_array($verb, $filterVerbs)) {
                continue;
            }
            
            $verbId = "v_" . md5($verb);
            
            // Add verb node if it doesn't exist
            if (!isset($nodeMap[$verbId])) {
                $nodes[] = [
                    'id' => $verbId,
                    'name' => $verb,
                    'type' => 'verb',
                    'group' => 2,
                    'details' => ['description' => 'Relationship type']
                ];
                $nodeMap[$verbId] = $nodeCount++;
            }
            
            // Link entity to verb
            $links[] = [
                'source' => $entityId,
                'target' => $verbId,
                'value' => 1
            ];
            
            // Process target concepts
            foreach ($targetConcepts as $targetConceptId) {
                $targetId = "t_" . $targetConceptId;
                
                // Get the concept
                $targetConcept = $this->system->conceptFactory->getConceptFromId($targetConceptId);
                
                // Add target concept node if it doesn't exist
                if (!isset($nodeMap[$targetId])) {
                    $nodes[] = [
                        'id' => $targetId,
                        'name' => $targetConcept ? $targetConcept->getShortname() : null,
                        'type' => 'concept',
                        'group' => 3,
                        'details' => [
                            'id' => $targetConceptId,
                            'shortname' => $targetConcept ? $targetConcept->getShortname() : null
                        ]
                    ];
                    $nodeMap[$targetId] = $nodeCount++;
                }
                
                // Link verb to target concept
                $links[] = [
                    'source' => $verbId,
                    'target' => $targetId,
                    'value' => 1
                ];
            }
        }
        
        // Process related entities if not at max depth
        if ($depth < $maxDepth) {
            // Get related entities through triplets
            $relatedEntities = $entity->getConnectedEntities();
            
            foreach ($relatedEntities as $relatedEntity) {
                $this->processEntity(
                    $relatedEntity, 
                    $nodes, 
                    $links, 
                    $nodeMap, 
                    $nodeCount, 
                    $depth + 1, 
                    $maxDepth, 
                    $includeReferences, 
                    $filterVerbs
                );
            }
        }
    }
    
    /**
     * Get a human-readable label for an entity
     * 
     * @param Entity $entity The entity to get a label for
     * @return string The entity label
     */

    /**
     * Generate D3.js compatible JSON for graph visualization
     * 
     * @param array $entityTypes Array of entity types to include in visualization
     * @param array $options Additional options for filtering the graph
     * @return string JSON string for D3.js visualization
     */
    public function generateD3Json(array $entityTypes = [], array $options = [])
    {
        $nodes = [];
        $links = [];
        $nodeMap = []; // Maps node IDs to indices
        $nodeCount = 0;
        
        // Process options
        $maxDepth = $options['maxDepth'] ?? 2;
        $includeReferences = $options['includeReferences'] ?? true;
        $filterVerbs = $options['filterVerbs'] ?? [];
        
        // If no entity types specified, get all entities
        if (empty($entityTypes)) {
            // Get a sample of entities from the system
            $sampleFactory = new EntityFactory('algebra', 'algebraFile', $this->system);
            $sampleFactory->populateLocal($this->config['maxNodes']);
            $entities = $sampleFactory->getEntities();
        } else {
            $entities = [];
            foreach ($entityTypes as $entityType) {
                $factory = new EntityFactory($entityType, '', $this->system);
                $factory->populateLocal(min(1000, $this->config['maxNodes'] - count($entities)));
                $entities = array_merge($entities, $factory->getEntities());
                
                if (count($entities) >= $this->config['maxNodes']) {
                    break;
                }
            }
        }
        
        // Process entities
        foreach ($entities as $entity) {
            $this->processEntity($entity, $nodes, $links, $nodeMap, $nodeCount, 0, $maxDepth, $includeReferences, $filterVerbs);
            
            if ($nodeCount >= $this->config['maxNodes']) {
                break;
            }
        }
        
        // Make sure we have at least one node to avoid D3 errors
        if (empty($nodes)) {
            $nodes[] = [
                'id' => 'empty',
                'name' => 'No data found',
                'type' => 'entity',
                'group' => 1,
                'details' => ['message' => 'No entities found for the specified types']
            ];
        }
        
        return json_encode([
            'nodes' => array_values($nodes),
            'links' => $links
        ]);
    }
    
    /**
     * Render HTML for the visualization
     * 
     * @param string $jsonData JSON data for D3.js
     * @param array $options Additional rendering options
     * @return string HTML content
     */
    public function renderHtml($jsonData, array $options = [])
    {
        $title = $options['title'] ?? 'My Datagraph Visualization';
        $width = $options['width'] ?? $this->config['width'];
        $height = $options['height'] ?? $this->config['height'];
        $showDetails = $options['showDetails'] ?? $this->config['showDetails'];
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <script src="https://d3js.org/d3.v7.min.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
        }
        
        #container {
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        #visualization-container {
            display: flex;
            flex-direction: row;
        }
        
        #graph {
            flex: 1;
            height: 800px;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        #details-panel {
            width: 300px;
            margin-left: 20px;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
            max-height: 800px;
        }
        
        .node {
            stroke: #fff;
            stroke-width: 1.5px;
        }
        
        .link {
            stroke: #999;
            stroke-opacity: 0.6;
        }
        
        .node text {
            font-size: 10px;
            fill: #333;
        }
        
        .controls {
            margin-bottom: 20px;
            padding: 10px;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .legend {
            display: flex;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            margin-right: 20px;
            margin-bottom: 5px;
        }
        
        .legend-color {
            width: 15px;
            height: 15px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .detail-section {
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }
        
        .detail-section h3 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #333;
        }
        
        .detail-section table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .detail-section table th, .detail-section table td {
            text-align: left;
            padding: 5px;
            border-bottom: 1px solid #eee;
        }
        
        .detail-section table th {
            font-weight: bold;
            color: #555;
        }
        
        .node-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            color: white;
            font-size: 12px;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div id="container">
        <h1>{$title}</h1>
        
        <div class="controls">
            <div>
                <label for="search">Search nodes: </label>
                <input type="text" id="search" placeholder="Type to search...">
                <button id="resetSearch">Reset</button>
                <button id="expandAll">Expand All</button>
                <button id="collapseAll">Collapse All</button>
            </div>
            
            <div class="legend">
                <div class="legend-item">
                    <div class="legend-color" style="background-color: {$this->config['colors']['entity']};"></div>
                    <span>Entity</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: {$this->config['colors']['concept']};"></div>
                    <span>Concept</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: {$this->config['colors']['verb']};"></div>
                    <span>Verb</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: {$this->config['colors']['reference']};"></div>
                    <span>Reference</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: {$this->config['colors']['value']};"></div>
                    <span>Value</span>
                </div>
            </div>
        </div>
        
        <div id="visualization-container">
            <div id="graph"></div>
            <div id="details-panel">
                <h2>Node Details</h2>
                <p>Click on a node to see details</p>
                <div id="node-details"></div>
            </div>
        </div>
    </div>
    
    <script>
        // Graph data
        const graphData = {$jsonData};
        
        // Color mapping
        const colorMap = {
            'entity': '{$this->config['colors']['entity']}',
            'concept': '{$this->config['colors']['concept']}',
            'verb': '{$this->config['colors']['verb']}',
            'reference': '{$this->config['colors']['reference']}',
            'value': '{$this->config['colors']['value']}'
        };
        
        // Fix the links to use node objects instead of indices
        const rawData = graphData;
        const processedData = {
            nodes: rawData.nodes,
            links: []
        };
        
        // Create a map of node IDs
        const nodeMap = {};
        rawData.nodes.forEach((node, index) => {
            nodeMap[node.id] = node;
        });
        
        // Convert links to use node objects
        rawData.links.forEach(link => {
            const sourceId = typeof link.source === 'object' ? link.source.id : link.source;
            const targetId = typeof link.target === 'object' ? link.target.id : link.target;
            
            if (nodeMap[sourceId] && nodeMap[targetId]) {
                processedData.links.push({
                    source: nodeMap[sourceId],
                    target: nodeMap[targetId],
                    value: link.value
                });
            }
        });
        
        // Create the force simulation
        const simulation = d3.forceSimulation(processedData.nodes)
            .force("link", d3.forceLink().links(processedData.links).id(d => d.id))
            .force("charge", d3.forceManyBody().strength({$this->config['charge']}))
            .force("center", d3.forceCenter({$width} / 2, {$height} / 2));
        
        // Create the SVG container
        const svg = d3.select("#graph")
            .append("svg")
            .attr("width", "100%")
            .attr("height", "100%")
            .attr("viewBox", [0, 0, {$width}, {$height}])
            .call(d3.zoom().on("zoom", (event) => {
                g.attr("transform", event.transform);
            }));
        
        const g = svg.append("g");
        
        // Add links
        const link = g.append("g")
            .attr("stroke", "#999")
            .attr("stroke-opacity", 0.6)
            .selectAll("line")
            .data(processedData.links)
            .join("line")
            .attr("stroke-width", d => Math.sqrt(d.value));
        
        // Add nodes
        const node = g.append("g")
            .attr("stroke", "#fff")
            .attr("stroke-width", 1.5)
            .selectAll("g")
            .data(processedData.nodes)
            .join("g")
            .call(drag(simulation))
            .on("click", showNodeDetails);
        
        // Add circles to nodes
        node.append("circle")
            .attr("r", 10)
            .attr("fill", d => colorMap[d.type] || "#1f77b4");
        
        // Add text labels to nodes
        node.append("text")
            .attr("x", 12)
            .attr("y", 3)
            .text(function(d) { return d.name || '(' + d.type + ' #' + d.id + ')'; })
            .clone(true).lower()
            .attr("fill", "none")
            .attr("stroke", "white")
            .attr("stroke-width", 3);
        
        // Add title for hover tooltip
        node.append("title")
            .text(function(d) { 
                let title = (d.name || '(' + d.type + ' #' + d.id + ')') + ' (' + d.type + ')';
                if (d.type === 'entity' && d.entityType) {
                    title += ' [' + d.entityType + ']';
                }
                return title;
            });
        
        // Update positions on each tick
        simulation.on("tick", () => {
            link
                .attr("x1", d => d.source.x)
                .attr("y1", d => d.source.y)
                .attr("x2", d => d.target.x)
                .attr("y2", d => d.target.y);
            
            node.attr("transform", d => `translate(\${d.x},\${d.y})`);
        });
        
        // Drag functionality
        function drag(simulation) {
            function dragstarted(event) {
                if (!event.active) simulation.alphaTarget(0.3).restart();
                event.subject.fx = event.subject.x;
                event.subject.fy = event.subject.y;
            }
            
            function dragged(event) {
                event.subject.fx = event.x;
                event.subject.fy = event.y;
            }
            
            function dragended(event) {
                if (!event.active) simulation.alphaTarget(0);
                event.subject.fx = null;
                event.subject.fy = null;
            }
            
            return d3.drag()
                .on("start", dragstarted)
                .on("drag", dragged)
                .on("end", dragended);
        }
        
        // Show node details in the panel
        function showNodeDetails(event, d) {
            const detailsPanel = document.getElementById('node-details');
            
            // Create badge for node type
            const typeBadge = `<span class="node-badge" style="background-color: \${colorMap[d.type]}">\${d.type}</span>`;
            
            let html = `
                <div class="detail-section">
                    <h3>\${typeBadge} \${d.name || '(Unnamed)'}</h3>
                    <p><strong>ID:</strong> \${d.id}</p>
                </div>
            `;
            
            // Add type-specific details
            if (d.type === 'entity') {
                html += `
                    <div class="detail-section">
                        <h3>Entity Details</h3>
                        <p><strong>Entity Type:</strong> \${d.entityType || 'Unknown'}</p>
                `;
                
                if (d.details && d.details.references) {
                    html += `<h4>References</h4>
                    <table>
                        <tr>
                            <th>Name</th>
                            <th>Value</th>
                        </tr>`;
                    
                    for (const [refName, refValue] of Object.entries(d.details.references)) {
                        html += `
                            <tr>
                                <td>\${refName}</td>
                                <td>\${refValue}</td>
                            </tr>
                        `;
                    }
                    
                    html += `</table>`;
                }
                
                html += `</div>`;
            } else if (d.type === 'concept') {
                html += `
                    <div class="detail-section">
                        <h3>Concept Details</h3>
                        <p><strong>ID:</strong> \${d.details?.id || 'Unknown'}</p>
                        <p><strong>Shortname:</strong> \${d.details?.shortname || 'None'}</p>
                    </div>
                `;
            } else if (d.type === 'reference') {
                html += `
                    <div class="detail-section">
                        <h3>Reference Details</h3>
                        <p><strong>Name:</strong> \${d.name}</p>
                        <p><strong>Description:</strong> \${d.details?.description || 'No description'}</p>
                    </div>
                `;
            } else if (d.type === 'verb') {
                html += `
                    <div class="detail-section">
                        <h3>Verb Details</h3>
                        <p><strong>Name:</strong> \${d.name}</p>
                        <p><strong>Description:</strong> \${d.details?.description || 'No description'}</p>
                    </div>
                `;
            } else if (d.type === 'value') {
                html += `
                    <div class="detail-section">
                        <h3>Value Details</h3>
                        <p><strong>Value:</strong> \${d.name}</p>
                        <p><strong>Reference:</strong> \${d.details?.reference || 'Unknown'}</p>
                    </div>
                `;
            }
            
            // Add connected nodes section
            html += `
                <div class="detail-section">
                    <h3>Connected Nodes</h3>
                    <ul>
            `;
            
            // Find connected nodes
            const connectedLinks = processedData.links.filter(link => 
                link.source.id === d.id || link.target.id === d.id
            );
            
            const connectedNodes = connectedLinks.map(link => 
                link.source.id === d.id ? link.target : link.source
            );
            
            if (connectedNodes.length === 0) {
                html += `<li>No connected nodes</li>`;
            } else {
                connectedNodes.forEach(node => {
                    const nodeBadge = `<span class="node-badge" style="background-color: \${colorMap[node.type]}">\${node.type}</span>`;
                    html += `<li>\${nodeBadge} \${node.name || '(Unnamed)'}</li>`;
                });
            }
            
            html += `
                    </ul>
                </div>
            `;
            
            detailsPanel.innerHTML = html;
        }
        
        // Search functionality
        document.getElementById('search').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            
            if (searchTerm === '') {
                // Reset all nodes and links
                node.style('opacity', 1);
                link.style('opacity', 0.6);
                return;
            }
            
            // Find matching nodes
            const matchingNodes = new Set();
            processedData.nodes.forEach((n, i) => {
                if ((n.name && n.name.toString().toLowerCase().includes(searchTerm)) || 
                    (n.id && n.id.toLowerCase().includes(searchTerm)) ||
                    (n.entityType && n.entityType.toLowerCase().includes(searchTerm))) {
                    matchingNodes.add(n.id);
                }
            });
            
            // Find connected links
            const connectedLinks = new Set();
            processedData.links.forEach((l, i) => {
                if (matchingNodes.has(l.source.id) || matchingNodes.has(l.target.id)) {
                    connectedLinks.add(i);
                    matchingNodes.add(l.source.id);
                    matchingNodes.add(l.target.id);
                }
            });
            
            // Update visibility
            node.style('opacity', d => matchingNodes.has(d.id) ? 1 : 0.1);
            link.style('opacity', (d, i) => connectedLinks.has(i) ? 0.6 : 0.1);
        });
        
        // Reset search
        document.getElementById('resetSearch').addEventListener('click', function() {
            document.getElementById('search').value = '';
            node.style('opacity', 1);
            link.style('opacity', 0.6);
        });
        
        // Expand all nodes
        document.getElementById('expandAll').addEventListener('click', function() {
            simulation.force("charge").strength(-1000);
            simulation.alpha(1).restart();
        });
        
                // Collapse all nodes
                document.getElementById('collapseAll').addEventListener('click', function() {
            simulation.force("charge").strength(-300);
            simulation.alpha(1).restart();
        });
        
        // Filter by type
        document.querySelectorAll('.type-filter').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                applyFilters();
            });
        });
        
        function applyFilters() {
            const selectedTypes = Array.from(document.querySelectorAll('.type-filter:checked'))
                .map(cb => cb.value);
            
            if (selectedTypes.length === 0) {
                // If no types selected, show all
                node.style('opacity', 1);
                link.style('opacity', 0.6);
                return;
            }
            
            // Find nodes of selected types
            const visibleNodes = new Set();
            processedData.nodes.forEach((n, i) => {
                if (selectedTypes.includes(n.type)) {
                    visibleNodes.add(n.id);
                }
            });
            
            // Find connected links
            const visibleLinks = new Set();
            processedData.links.forEach((l, i) => {
                if (visibleNodes.has(l.source.id) && visibleNodes.has(l.target.id)) {
                    visibleLinks.add(i);
                }
            });
            
            // Update visibility
            node.style('opacity', d => visibleNodes.has(d.id) ? 1 : 0.1);
            link.style('opacity', (d, i) => visibleLinks.has(i) ? 0.6 : 0.1);
        }
    </script>
</body>
</html>
HTML;
        
        return $html;
    }
    
    /**
     * Save the visualization to an HTML file
     * 
     * @param string $jsonData JSON data for D3.js
     * @param string $filePath Path to save the HTML file
     * @param array $options Additional rendering options
     * @return bool True if file was saved successfully
     */
    public function saveToFile($jsonData, $filePath, array $options = [])
    {
        $html = $this->renderHtml($jsonData, $options);
        return file_put_contents($filePath, $html) !== false;
    }
    
    /**
     * Generate and save a visualization for specific entity types
     * 
     * @param array $entityTypes Array of entity types to include
     * @param string $filePath Path to save the HTML file
     * @param array $options Additional options
     * @return bool True if file was saved successfully
     */
    public function visualizeEntityTypes(array $entityTypes, $filePath, array $options = [])
    {
        $jsonData = $this->generateD3Json($entityTypes, $options);
        return $this->saveToFile($jsonData, $filePath, $options);
    }
    
    /**
     * Generate and save a visualization for a specific entity and its relationships
     * 
     * @param Entity $entity The entity to visualize
     * @param string $filePath Path to save the HTML file
     * @param array $options Additional options
     * @return bool True if file was saved successfully
     */
    public function visualizeEntity(Entity $entity, $filePath, array $options = [])
    {
        $nodes = [];
        $links = [];
        $nodeMap = [];
        $nodeCount = 0;
        
        $maxDepth = $options['maxDepth'] ?? 3;
        $includeReferences = $options['includeReferences'] ?? true;
        $filterVerbs = $options['filterVerbs'] ?? [];
        
        $this->processEntity(
            $entity, 
            $nodes, 
            $links, 
            $nodeMap, 
            $nodeCount, 
            0, 
            $maxDepth, 
            $includeReferences, 
            $filterVerbs
        );
        
        // Make sure we have at least one node
        if (empty($nodes)) {
            $nodes[] = [
                'id' => 'empty',
                'name' => 'No data found for this entity',
                'type' => 'entity',
                'group' => 1
            ];
        }
        
        // Convert links to use node indices
        $indexedLinks = [];
        foreach ($links as $link) {
            if (isset($nodeMap[$link['source']]) && isset($nodeMap[$link['target']])) {
                $indexedLinks[] = [
                    'source' => $nodeMap[$link['source']],
                    'target' => $nodeMap[$link['target']],
                    'value' => $link['value']
                ];
            }
        }
        
        $jsonData = json_encode([
            'nodes' => array_values($nodes),
            'links' => $indexedLinks
        ]);
        
        $options['title'] = $options['title'] ?? 'Entity: ' . $this->getEntityLabel($entity);
        
        return $this->saveToFile($jsonData, $filePath, $options);
    }
    
    /**
     * Get a human-readable label for an entity
     * 
     * @param Entity $entity The entity to get a label for
     * @return string The entity label
     */
    private function getEntityLabel(Entity $entity)
    {
        // Try to get a name or title reference
        foreach (['name', 'title', 'id', 'identifier'] as $nameField) {
            $name = $entity->get($nameField);
            if (!empty($name)) {
                return $name;
            }
        }
        
        // Fall back to concept shortname
        $shortname = $entity->subjectConcept->getShortname();
        
        // If shortname is null, use the concept ID
        if ($shortname === null) {
            return 'Entity #' . $entity->id;
        }
        
        return $shortname . ' #' . $entity->id;
    }
    
    /**
     * Process entity references for visualization
     * 
     * @param Entity $entity The entity to process references for
     * @param array &$nodes Array of nodes to populate
     * @param array &$links Array of links to populate
     * @param array &$nodeMap Map of node IDs to indices
     * @param int &$nodeCount Running count of nodes
     * @return void
     */
    private function processEntityReferences($entity, &$nodes, &$links, &$nodeMap, &$nodeCount)
    {
        $entityId = "e_" . $entity->id;
        
        // Add references to the entity details
        $entityIndex = $nodeMap[$entityId];
        if (!isset($nodes[$entityIndex]['details']['references'])) {
            $nodes[$entityIndex]['details']['references'] = [];
        }
        
        foreach ($entity->entityRefs as $refName => $refValues) {
            $refId = "r_" . md5($refName);
            
            // Add reference node if it doesn't exist
            if (!isset($nodeMap[$refId])) {
                $nodes[] = [
                    'id' => $refId,
                    'name' => $refName,
                    'type' => 'reference',
                    'group' => 4,
                    'details' => [
                        'description' => 'Entity reference'
                    ]
                ];
                $nodeMap[$refId] = $nodeCount++;
            }
            
            // Add link from entity to reference
            $links[] = [
                'source' => $entityId,
                'target' => $refId,
                'value' => 1
            ];
            
            // Process reference values
            foreach ($refValues as $refValue) {
                $valueId = "rv_" . md5($refValue);
                
                // Add value node if it doesn't exist
                if (!isset($nodeMap[$valueId])) {
                    $nodes[] = [
                        'id' => $valueId,
                        'name' => $refValue,
                        'type' => 'value',
                        'group' => 5,
                        'details' => [
                            'reference' => $refName
                        ]
                    ];
                    $nodeMap[$valueId] = $nodeCount++;
                }
                
                // Add link from reference to value
                $links[] = [
                    'source' => $refId,
                    'target' => $valueId,
                    'value' => 1
                ];
                
                // Add reference value to entity details
                $nodes[$entityIndex]['details']['references'][$refName] = $refValue;
            }
        }
    }
}
