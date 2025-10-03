<?php
session_start();
require __DIR__ . '/../db.php';

// --- Handle Add to Cart via POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'])) {
    $item_id = intval($_POST['item_id']);
    $quantity = isset($_POST['quantity']) ? max(1, intval($_POST['quantity'])) : 1;

    if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

    if (isset($_SESSION['cart'][$item_id])) {
        $_SESSION['cart'][$item_id] += $quantity;
    } else {
        $_SESSION['cart'][$item_id] = $quantity;
    }

    echo json_encode(['status'=>'success','message'=>'Added to cart']);
    exit;
}

// --- Retrieve item via GET ---
$item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;
$item = null;
if ($item_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM stock WHERE id=? AND status='active'");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) $item = $result->fetch_assoc();
}

if (!$item) { 
    echo "<h2>No product selected or inactive.</h2>"; 
    exit; 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Design <?php echo htmlspecialchars($item['name']); ?> - Marcomedia Customizer</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --primary-color: #2c5aa0;
    --secondary-color: #1a3a6c;
    --accent-color: #4a7bd9;
    --light-bg: #f8fafc;
    --dark-text: #2d3748;
    --card-bg: #ffffff;
    --border-color: #e2e8f0;
    --success-color: #38a169;
    --warning-color: #ecc94b;
    --danger-color: #e53e3e;
}
* {
    box-sizing: border-box;
}
body {
    margin: 0;
    font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
    background: var(--light-bg);
    color: var(--dark-text);
    line-height: 1.6;
}
header {
    background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
    color: #fff;
    padding: 16px 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    position: sticky;
    top: 0;
    z-index: 1000;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.header-content {
    display: flex;
    align-items: center;
    gap: 15px;
}
.logo {
    font-weight: 700;
    font-size: 22px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.logo i {
    font-size: 24px;
}
.header-actions {
    display: flex;
    gap: 15px;
}
.header-actions button {
    background: rgba(255,255,255,0.2);
    color: white;
    border: 1px solid rgba(255,255,255,0.3);
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 500;
}
.header-actions button:hover {
    background: rgba(255,255,255,0.3);
}
main {
    display: flex;
    max-width: 1400px;
    margin: 30px auto;
    padding: 0 20px;
    gap: 30px;
}
.product-preview {
    flex: 1;
    background: var(--card-bg);
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.preview-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.preview-header h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
}
.preview-actions {
    display: flex;
    gap: 10px;
}
.preview-actions button {
    background: var(--light-bg);
    border: 1px solid var(--border-color);
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}
.preview-actions button:hover {
    background: var(--accent-color);
    color: white;
}
.preview-container {
    flex: 1;
    padding: 30px;
    display: flex;
    justify-content: center;
    align-items: center;
    background: #f1f5f9;
}
#designCanvas {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    max-width: 100%;
    height: auto;
}
.customization-panel {
    width: 350px;
    background: var(--card-bg);
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    overflow: hidden;
}
.panel-tabs {
    display: flex;
    border-bottom: 1px solid var(--border-color);
}
.panel-tab {
    flex: 1;
    text-align: center;
    padding: 16px;
    background: none;
    border: none;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.3s;
    border-bottom: 3px solid transparent;
}
.panel-tab.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
}
.panel-content {
    padding: 24px;
}
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}
.control-group {
    margin-bottom: 24px;
}
.control-group h3 {
    margin: 0 0 16px 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--secondary-color);
    display: flex;
    align-items: center;
    gap: 8px;
}
.control-group h3 i {
    font-size: 18px;
}
.control-row {
    display: flex;
    gap: 12px;
    margin-bottom: 12px;
    align-items: center;
}
.control-row label {
    font-size: 14px;
    min-width: 80px;
}
.control-row input, .control-row select {
    flex: 1;
    padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 14px;
}
.color-preview {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    border: 1px solid var(--border-color);
    cursor: pointer;
}
.color-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 10px;
    margin-top: 10px;
}
.color-option {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    cursor: pointer;
    border: 2px solid transparent;
    transition: transform 0.2s;
}
.color-option:hover {
    transform: scale(1.1);
}
.color-option.selected {
    border-color: var(--dark-text);
    transform: scale(1.1);
}
.upload-area {
    border: 2px dashed var(--border-color);
    border-radius: 8px;
    padding: 30px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    margin-bottom: 15px;
}
.upload-area:hover {
    border-color: var(--accent-color);
    background: rgba(74, 123, 217, 0.05);
}
.upload-area i {
    font-size: 32px;
    color: var(--accent-color);
    margin-bottom: 10px;
}
.upload-area p {
    margin: 0;
    font-size: 14px;
}
.upload-area span {
    color: var(--accent-color);
    font-weight: 500;
}
.btn {
    padding: 12px 20px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    font-size: 15px;
}
.btn-primary {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(44, 90, 160, 0.3);
}
.btn-secondary {
    background: var(--light-bg);
    color: var(--dark-text);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover {
    background: #e2e8f0;
}
.btn-danger {
    background: var(--danger-color);
    color: white;
}
.btn-danger:hover {
    background: #c53030;
}
.design-elements {
    margin-top: 20px;
}
.design-element {
    display: flex;
    align-items: center;
    padding: 12px;
    background: var(--light-bg);
    border-radius: 8px;
    margin-bottom: 10px;
    cursor: pointer;
    transition: all 0.2s;
}
.design-element:hover {
    background: #e2e8f0;
}
.design-element.active {
    background: var(--accent-color);
    color: white;
}
.element-icon {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 18px;
}
.element-info {
    flex: 1;
}
.element-info h4 {
    margin: 0 0 4px 0;
    font-size: 14px;
    font-weight: 600;
}
.element-info p {
    margin: 0;
    font-size: 12px;
    opacity: 0.8;
}
.element-actions {
    display: flex;
    gap: 8px;
}
.element-actions button {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    opacity: 0.7;
    transition: opacity 0.2s;
}
.element-actions button:hover {
    opacity: 1;
}
footer {
    margin-top: 40px;
    font-size: 14px;
    text-align: center;
    padding: 20px;
    background: var(--secondary-color);
    color: #fff;
    border-top: 3px solid var(--primary-color);
}
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    justify-content: center;
    align-items: center;
    z-index: 2000;
}
.modal-content {
    background: var(--card-bg);
    padding: 30px;
    border-radius: 12px;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    text-align: center;
}
.modal h3 {
    margin-top: 0;
    color: var(--secondary-color);
}
.modal-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    justify-content: center;
}
@media (max-width: 1024px) {
    main {
        flex-direction: column;
    }
    .customization-panel {
        width: 100%;
    }
}
</style>
</head>
<body>

<header>
    <div class="header-content">
        <div class="logo">
            <i class="fas fa-palette"></i>
            <span>Marcomedia Customizer</span>
        </div>
        <h1>Design Your <?php echo htmlspecialchars($item['name']); ?></h1>
    </div>
    <div class="header-actions">
        <button id="saveBtn"><i class="fas fa-save"></i> Save</button>
        <button id="backBtn"><i class="fas fa-arrow-left"></i> Back</button>
    </div>
</header>

<main>
    <section class="product-preview">
        <div class="preview-header">
            <h2>Design Preview</h2>
            <div class="preview-actions">
                <button id="zoomInBtn"><i class="fas fa-search-plus"></i></button>
                <button id="zoomOutBtn"><i class="fas fa-search-minus"></i></button>
                <button id="resetViewBtn"><i class="fas fa-sync-alt"></i></button>
            </div>
        </div>
        <div class="preview-container">
            <canvas id="designCanvas" width="900" height="600"></canvas>
        </div>
    </section>

    <section class="customization-panel">
        <div class="panel-tabs">
            <button class="panel-tab active" data-tab="design">Design</button>
            <button class="panel-tab" data-tab="text">Text</button>
            <button class="panel-tab" data-tab="images">Images</button>
            <button class="panel-tab" data-tab="elements">Elements</button>
        </div>
        
        <div class="panel-content">
            <!-- Design Tab -->
            <div class="tab-content active" id="design-tab">
                <div class="control-group">
                    <h3><i class="fas fa-fill-drip"></i> Product Colors</h3>
                    <div class="color-grid">
                        <div class="color-option selected" style="background-color: #ffffff;" data-color="#ffffff"></div>
                        <div class="color-option" style="background-color: #000000;" data-color="#000000"></div>
                        <div class="color-option" style="background-color: #2c5aa0;" data-color="#2c5aa0"></div>
                        <div class="color-option" style="background-color: #38a169;" data-color="#38a169"></div>
                        <div class="color-option" style="background-color: #e53e3e;" data-color="#e53e3e"></div>
                        <div class="color-option" style="background-color: #ecc94b;" data-color="#ecc94b"></div>
                        <div class="color-option" style="background-color: #805ad5;" data-color="#805ad5"></div>
                        <div class="color-option" style="background-color: #ed8936;" data-color="#ed8936"></div>
                        <div class="color-option" style="background-color: #1a202c;" data-color="#1a202c"></div>
                        <div class="color-option" style="background-color: #a0aec0;" data-color="#a0aec0"></div>
                    </div>
                </div>
                
                <div class="control-group">
                    <h3><i class="fas fa-paint-brush"></i> Custom Color</h3>
                    <div class="control-row">
                        <label>Color:</label>
                        <input type="color" id="colorPicker" value="#2c5aa0">
                    </div>
                </div>
                
                <button class="btn btn-secondary" id="resetDesignBtn">
                    <i class="fas fa-undo"></i> Reset Design
                </button>
            </div>
            
            <!-- Text Tab -->
            <div class="tab-content" id="text-tab">
                <div class="control-group">
                    <h3><i class="fas fa-font"></i> Add Text</h3>
                    <div class="control-row">
                        <label>Text:</label>
                        <input type="text" id="textInput" placeholder="Enter your text">
                    </div>
                    <div class="control-row">
                        <label>Font:</label>
                        <select id="fontSelect">
                            <option value="Arial">Arial</option>
                            <option value="Verdana">Verdana</option>
                            <option value="Georgia">Georgia</option>
                            <option value="Times New Roman">Times New Roman</option>
                            <option value="Courier New">Courier New</option>
                            <option value="Impact">Impact</option>
                        </select>
                    </div>
                    <div class="control-row">
                        <label>Size:</label>
                        <input type="range" id="fontSize" min="10" max="72" value="24">
                        <span id="fontSizeValue">24px</span>
                    </div>
                    <div class="control-row">
                        <label>Color:</label>
                        <input type="color" id="textColor" value="#000000">
                    </div>
                    <div class="control-row">
                        <button class="btn btn-secondary" id="addTextBtn">
                            <i class="fas fa-plus"></i> Add Text
                        </button>
                    </div>
                </div>
                
                <div class="control-group">
                    <h3><i class="fas fa-text-width"></i> Text Elements</h3>
                    <div class="design-elements" id="textElements">
                        <!-- Text elements will be added here dynamically -->
                    </div>
                </div>
            </div>
            
            <!-- Images Tab -->
            <div class="tab-content" id="images-tab">
                <div class="control-group">
                    <h3><i class="fas fa-image"></i> Upload Image</h3>
                    <div class="upload-area" id="uploadTrigger">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>Drag & drop your image here or <span>click to browse</span></p>
                    </div>
                    <input type="file" id="imageUpload" accept="image/*" style="display: none;">
                    <p style="font-size: 12px; color: #718096; text-align: center;">
                        Recommended: PNG with transparent background, max 5MB
                    </p>
                </div>
                
                <div class="control-group">
                    <h3><i class="fas fa-images"></i> Image Elements</h3>
                    <div class="design-elements" id="imageElements">
                        <!-- Image elements will be added here dynamically -->
                    </div>
                </div>
            </div>
            
            <!-- Elements Tab -->
            <div class="tab-content" id="elements-tab">
                <div class="control-group">
                    <h3><i class="fas fa-shapes"></i> Design Elements</h3>
                    <div class="color-grid">
                        <div class="color-option" style="background-color: #e53e3e; display: flex; align-items: center; justify-content: center; color: white;">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div class="color-option" style="background-color: #38a169; display: flex; align-items: center; justify-content: center; color: white;">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="color-option" style="background-color: #2c5aa0; display: flex; align-items: center; justify-content: center; color: white;">
                            <i class="fas fa-circle"></i>
                        </div>
                        <div class="color-option" style="background-color: #ecc94b; display: flex; align-items: center; justify-content: center; color: white;">
                            <i class="fas fa-square"></i>
                        </div>
                        <div class="color-option" style="background-color: #805ad5; display: flex; align-items: center; justify-content: center; color: white;">
                            <i class="fas fa-cloud"></i>
                        </div>
                    </div>
                </div>
                
                <div class="control-group">
                    <h3><i class="fas fa-object-group"></i> Added Elements</h3>
                    <div class="design-elements" id="shapeElements">
                        <!-- Shape elements will be added here dynamically -->
                    </div>
                </div>
            </div>
            
            <div class="control-group" style="margin-top: 30px;">
                <button class="btn btn-primary" id="addToCartBtn">
                    <i class="fas fa-shopping-cart"></i> Add to Cart - $<?php echo number_format($item['price'], 2); ?>
                </button>
            </div>
        </div>
    </section>
</main>

<!-- Confirmation Modal -->
<div class="modal" id="backConfirmModal">
    <div class="modal-content">
        <h3>Leave Design Editor?</h3>
        <p>Your current design has not been saved. Are you sure you want to leave?</p>
        <div class="modal-actions">
            <button class="btn btn-danger" id="confirmBack">Yes, Leave</button>
            <button class="btn btn-secondary" id="cancelBack">Cancel</button>
        </div>
    </div>
</div>

<footer>
    &copy; <?php echo date('Y'); ?> Marcomedia Customizer - Professional Product Design Tool
</footer>

<script>
// Global variables
const canvas = document.getElementById('designCanvas');
const ctx = canvas.getContext('2d');
let currentColor = '#2c5aa0';
let texts = [], images = [], shapes = [], parts = [];
let selectedPart = null, draggingItem = null, dragOffset = {x:0,y:0};
let zoomLevel = 1;
let selectedElement = null;

// Initialize product parts based on product type
function initParts(){
    const name = "<?php echo addslashes($item['name']); ?>";
    parts = [];
    
    // Reset canvas transform
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    
    if(/T-Shirt|Shirt|Jersey|Polo|Sweatshirt/i.test(name)){
        parts.push({name:'Body', x:250, y:150, w:400, h:300, color:'#ffffff'});
        parts.push({name:'Left Sleeve', x:150, y:150, w:100, h:150, color:'#ffffff'});
        parts.push({name:'Right Sleeve', x:650, y:150, w:100, h:150, color:'#ffffff'});
    } else if(/Mug|Magic Mug|Regular Mug/i.test(name)){
        parts.push({name:'Body', x:300, y:150, w:200, h:300, color:'#ffffff'});
        parts.push({name:'Handle', x:500, y:200, w:50, h:150, color:'#ffffff'});
    } else if(/Umbrella/i.test(name)){
        parts.push({name:'Canopy', x:250, y:150, w:400, h:200, color:'#ffffff'});
        parts.push({name:'Handle', x:420, y:350, w:40, h:150, color:'#ffffff'});
    } else if(/Keychain|Badge Pin|Lanyard|Medal|Totebag/i.test(name)){
        parts.push({name:'Main', x:250, y:200, w:400, h:250, color:'#ffffff'});
    } else {
        parts.push({name:'Main', x:200, y:150, w:500, h:300, color:'#ffffff'});
    }
    
    draw();
}

// Draw everything on canvas
function draw(){
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    // Apply zoom
    ctx.save();
    ctx.scale(zoomLevel, zoomLevel);
    
    // Draw product parts
    parts.forEach(p => {
        ctx.fillStyle = p.color;
        ctx.fillRect(p.x, p.y, p.w, p.h);
        
        // Add some styling to make parts look more realistic
        ctx.strokeStyle = 'rgba(0,0,0,0.1)';
        ctx.lineWidth = 1;
        ctx.strokeRect(p.x, p.y, p.w, p.h);
    });
    
    // Draw images
    images.forEach(i => {
        ctx.drawImage(i.img, i.x, i.y, i.w, i.h);
    });
    
    // Draw shapes
    shapes.forEach(s => {
        ctx.fillStyle = s.color;
        if(s.type === 'circle') {
            ctx.beginPath();
            ctx.arc(s.x, s.y, s.radius, 0, Math.PI * 2);
            ctx.fill();
        } else if(s.type === 'square') {
            ctx.fillRect(s.x - s.size/2, s.y - s.size/2, s.size, s.size);
        } else if(s.type === 'heart') {
            drawHeart(s.x, s.y, s.size, s.color);
        } else if(s.type === 'star') {
            drawStar(s.x, s.y, s.size, s.color);
        } else if(s.type === 'cloud') {
            drawCloud(s.x, s.y, s.size, s.color);
        }
    });
    
    // Draw texts
    texts.forEach(t => {
        ctx.fillStyle = t.color;
        ctx.font = `${t.bold ? 'bold ' : ''}${t.italic ? 'italic ' : ''}${t.size}px ${t.font}`;
        ctx.fillText(t.text, t.x, t.y);
        
        // Highlight selected text
        if(t === selectedElement) {
            ctx.strokeStyle = '#4a7bd9';
            ctx.lineWidth = 2;
            const metrics = ctx.measureText(t.text);
            ctx.strokeRect(t.x - 5, t.y - t.size, metrics.width + 10, t.size + 10);
        }
    });
    
    ctx.restore();
}

// Helper functions for drawing shapes
function drawHeart(x, y, size, color) {
    ctx.save();
    ctx.fillStyle = color;
    ctx.translate(x, y);
    ctx.scale(size / 50, size / 50);
    ctx.beginPath();
    ctx.moveTo(75, 40);
    ctx.bezierCurveTo(75, 37, 70, 25, 50, 25);
    ctx.bezierCurveTo(20, 25, 20, 62.5, 20, 62.5);
    ctx.bezierCurveTo(20, 80, 40, 102, 75, 120);
    ctx.bezierCurveTo(110, 102, 130, 80, 130, 62.5);
    ctx.bezierCurveTo(130, 62.5, 130, 25, 100, 25);
    ctx.bezierCurveTo(85, 25, 75, 37, 75, 40);
    ctx.fill();
    ctx.restore();
}

function drawStar(x, y, size, color) {
    ctx.save();
    ctx.fillStyle = color;
    ctx.translate(x, y);
    ctx.scale(size / 50, size / 50);
    ctx.beginPath();
    for (let i = 0; i < 5; i++) {
        ctx.lineTo(Math.cos((18 + i * 72) / 180 * Math.PI) * 50, 
                  -Math.sin((18 + i * 72) / 180 * Math.PI) * 50);
        ctx.lineTo(Math.cos((54 + i * 72) / 180 * Math.PI) * 20, 
                  -Math.sin((54 + i * 72) / 180 * Math.PI) * 20);
    }
    ctx.closePath();
    ctx.fill();
    ctx.restore();
}

function drawCloud(x, y, size, color) {
    ctx.save();
    ctx.fillStyle = color;
    ctx.translate(x, y);
    ctx.scale(size / 100, size / 100);
    ctx.beginPath();
    ctx.arc(0, 0, 30, 0, Math.PI * 2);
    ctx.arc(30, -20, 25, 0, Math.PI * 2);
    ctx.arc(60, 0, 35, 0, Math.PI * 2);
    ctx.arc(30, 20, 25, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
}

// Initialize the design
initParts();

// Tab switching
document.querySelectorAll('.panel-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.panel-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        tab.classList.add('active');
        document.getElementById(`${tab.dataset.tab}-tab`).classList.add('active');
    });
});

// Color selection
document.querySelectorAll('.color-option').forEach(option => {
    option.addEventListener('click', () => {
        if(option.dataset.color) {
            document.querySelectorAll('.color-option').forEach(o => o.classList.remove('selected'));
            option.classList.add('selected');
            currentColor = option.dataset.color;
            document.getElementById('colorPicker').value = currentColor;
            
            if(selectedPart) {
                selectedPart.color = currentColor;
                draw();
            }
        }
    });
});

document.getElementById('colorPicker').addEventListener('change', e => {
    currentColor = e.target.value;
    if(selectedPart) {
        selectedPart.color = currentColor;
        draw();
    }
});

// Text handling
document.getElementById('addTextBtn').addEventListener('click', () => {
    const text = document.getElementById('textInput').value.trim();
    if(!text) return;
    
    const textObj = {
        text,
        x: 300,
        y: 300,
        color: document.getElementById('textColor').value,
        font: document.getElementById('fontSelect').value,
        size: parseInt(document.getElementById('fontSize').value),
        bold: false,
        italic: false,
        id: Date.now()
    };
    
    texts.push(textObj);
    addTextElement(textObj);
    draw();
    document.getElementById('textInput').value = '';
});

document.getElementById('fontSize').addEventListener('input', e => {
    document.getElementById('fontSizeValue').textContent = `${e.target.value}px`;
});

function addTextElement(textObj) {
    const textElements = document.getElementById('textElements');
    const element = document.createElement('div');
    element.className = 'design-element';
    element.dataset.id = textObj.id;
    
    element.innerHTML = `
        <div class="element-icon">
            <i class="fas fa-font"></i>
        </div>
        <div class="element-info">
            <h4>${textObj.text.substring(0, 15)}${textObj.text.length > 15 ? '...' : ''}</h4>
            <p>${textObj.font}, ${textObj.size}px</p>
        </div>
        <div class="element-actions">
            <button class="edit-text"><i class="fas fa-edit"></i></button>
            <button class="delete-text"><i class="fas fa-trash"></i></button>
        </div>
    `;
    
    element.addEventListener('click', () => {
        selectedElement = textObj;
        document.querySelectorAll('.design-element').forEach(el => el.classList.remove('active'));
        element.classList.add('active');
        draw();
    });
    
    element.querySelector('.edit-text').addEventListener('click', (e) => {
        e.stopPropagation();
        document.getElementById('textInput').value = textObj.text;
        document.getElementById('fontSelect').value = textObj.font;
        document.getElementById('fontSize').value = textObj.size;
        document.getElementById('fontSizeValue').textContent = `${textObj.size}px`;
        document.getElementById('textColor').value = textObj.color;
        
        // Switch to text tab
        document.querySelectorAll('.panel-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelector('.panel-tab[data-tab="text"]').classList.add('active');
        document.getElementById('text-tab').classList.add('active');
    });
    
    element.querySelector('.delete-text').addEventListener('click', (e) => {
        e.stopPropagation();
        texts = texts.filter(t => t.id !== textObj.id);
        element.remove();
        draw();
    });
    
    textElements.appendChild(element);
}

// Image handling
document.getElementById('uploadTrigger').addEventListener('click', () => {
    document.getElementById('imageUpload').click();
});

document.getElementById('imageUpload').addEventListener('change', e => {
    const file = e.target.files[0]; 
    if(!file) return;
    
    const img = new Image();
    img.onload = () => {
        const imgObj = {
            img,
            x: 300,
            y: 200,
            w: 200,
            h: 150,
            id: Date.now()
        };
        
        images.push(imgObj);
        addImageElement(imgObj);
        draw();
    };
    img.src = URL.createObjectURL(file);
});

function addImageElement(imgObj) {
    const imageElements = document.getElementById('imageElements');
    const element = document.createElement('div');
    element.className = 'design-element';
    element.dataset.id = imgObj.id;
    
    element.innerHTML = `
        <div class="element-icon">
            <i class="fas fa-image"></i>
        </div>
        <div class="element-info">
            <h4>Uploaded Image</h4>
            <p>${imgObj.w}×${imgObj.h}px</p>
        </div>
        <div class="element-actions">
            <button class="delete-image"><i class="fas fa-trash"></i></button>
        </div>
    `;
    
    element.addEventListener('click', () => {
        selectedElement = imgObj;
        document.querySelectorAll('.design-element').forEach(el => el.classList.remove('active'));
        element.classList.add('active');
        draw();
    });
    
    element.querySelector('.delete-image').addEventListener('click', (e) => {
        e.stopPropagation();
        images = images.filter(i => i.id !== imgObj.id);
        element.remove();
        draw();
    });
    
    imageElements.appendChild(element);
}

// Shape handling
document.querySelectorAll('.color-option i').forEach(icon => {
    icon.parentElement.addEventListener('click', function() {
        const type = this.querySelector('i').className.split('fa-')[1];
        let shapeObj;
        
        switch(type) {
            case 'heart':
                shapeObj = {type: 'heart', x: 400, y: 300, size: 30, color: currentColor, id: Date.now()};
                break;
            case 'star':
                shapeObj = {type: 'star', x: 400, y: 300, size: 30, color: currentColor, id: Date.now()};
                break;
            case 'circle':
                shapeObj = {type: 'circle', x: 400, y: 300, radius: 20, color: currentColor, id: Date.now()};
                break;
            case 'square':
                shapeObj = {type: 'square', x: 400, y: 300, size: 40, color: currentColor, id: Date.now()};
                break;
            case 'cloud':
                shapeObj = {type: 'cloud', x: 400, y: 300, size: 40, color: currentColor, id: Date.now()};
                break;
        }
        
        if(shapeObj) {
            shapes.push(shapeObj);
            addShapeElement(shapeObj);
            draw();
        }
    });
});

function addShapeElement(shapeObj) {
    const shapeElements = document.getElementById('shapeElements');
    const element = document.createElement('div');
    element.className = 'design-element';
    element.dataset.id = shapeObj.id;
    
    let typeName = shapeObj.type.charAt(0).toUpperCase() + shapeObj.type.slice(1);
    
    element.innerHTML = `
        <div class="element-icon" style="background-color: ${shapeObj.color};">
            <i class="fas fa-${shapeObj.type}" style="color: white;"></i>
        </div>
        <div class="element-info">
            <h4>${typeName}</h4>
            <p>${shapeObj.color}</p>
        </div>
        <div class="element-actions">
            <button class="delete-shape"><i class="fas fa-trash"></i></button>
        </div>
    `;
    
    element.addEventListener('click', () => {
        selectedElement = shapeObj;
        document.querySelectorAll('.design-element').forEach(el => el.classList.remove('active'));
        element.classList.add('active');
        draw();
    });
    
    element.querySelector('.delete-shape').addEventListener('click', (e) => {
        e.stopPropagation();
        shapes = shapes.filter(s => s.id !== shapeObj.id);
        element.remove();
        draw();
    });
    
    shapeElements.appendChild(element);
}

// Canvas interactions
canvas.addEventListener('mousedown', e => {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const x = (e.clientX - rect.left) * scaleX / zoomLevel;
    const y = (e.clientY - rect.top) * scaleY / zoomLevel;
    
    // Check if clicked on a text element
    for(let i = texts.length - 1; i >= 0; i--){
        const t = texts[i];
        ctx.font = `${t.size}px ${t.font}`;
        const metrics = ctx.measureText(t.text);
        if(x >= t.x && x <= t.x + metrics.width && y >= t.y - t.size && y <= t.y){
            draggingItem = t;
            dragOffset = {x: x - t.x, y: y - t.y};
            selectedElement = t;
            updateElementSelection();
            return;
        }
    }
    
    // Check if clicked on an image
    for(let i = images.length - 1; i >= 0; i--){
        const img = images[i];
        if(x >= img.x && x <= img.x + img.w && y >= img.y && y <= img.y + img.h){
            draggingItem = img;
            dragOffset = {x: x - img.x, y: y - img.y};
            selectedElement = img;
            updateElementSelection();
            return;
        }
    }
    
    // Check if clicked on a shape
    for(let i = shapes.length - 1; i >= 0; i--){
        const s = shapes[i];
        let clicked = false;
        
        if(s.type === 'circle') {
            const distance = Math.sqrt((x - s.x) ** 2 + (y - s.y) ** 2);
            clicked = distance <= s.radius;
        } else if(s.type === 'square') {
            clicked = x >= s.x - s.size/2 && x <= s.x + s.size/2 && 
                      y >= s.y - s.size/2 && y <= s.y + s.size/2;
        } else {
            // For complex shapes, use a simple bounding box
            clicked = x >= s.x - 30 && x <= s.x + 30 && 
                      y >= s.y - 30 && y <= s.y + 30;
        }
        
        if(clicked) {
            draggingItem = s;
            dragOffset = {x: x - s.x, y: y - s.y};
            selectedElement = s;
            updateElementSelection();
            return;
        }
    }
    
    // Check if clicked on a product part
    selectedPart = parts.find(p => x >= p.x && x <= p.x + p.w && y >= p.y && y <= p.y + p.h);
    if(selectedPart) {
        selectedPart.color = currentColor;
        selectedElement = null;
        updateElementSelection();
        draw();
    }
});

canvas.addEventListener('mousemove', e => {
    if(draggingItem){
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        draggingItem.x = (e.clientX - rect.left) * scaleX / zoomLevel - dragOffset.x;
        draggingItem.y = (e.clientY - rect.top) * scaleY / zoomLevel - dragOffset.y;
        draw();
    }
});

canvas.addEventListener('mouseup', () => {
    draggingItem = null;
});

canvas.addEventListener('mouseleave', () => {
    draggingItem = null;
});

function updateElementSelection() {
    document.querySelectorAll('.design-element').forEach(el => el.classList.remove('active'));
    
    if(selectedElement) {
        const element = document.querySelector(`.design-element[data-id="${selectedElement.id}"]`);
        if(element) element.classList.add('active');
    }
    
    draw();
}

// Zoom controls
document.getElementById('zoomInBtn').addEventListener('click', () => {
    if(zoomLevel < 2) {
        zoomLevel += 0.1;
        draw();
    }
});

document.getElementById('zoomOutBtn').addEventListener('click', () => {
    if(zoomLevel > 0.5) {
        zoomLevel -= 0.1;
        draw();
    }
});

document.getElementById('resetViewBtn').addEventListener('click', () => {
    zoomLevel = 1;
    draw();
});

// Reset design
document.getElementById('resetDesignBtn').addEventListener('click', () => {
    if(confirm('Are you sure you want to reset your design? This cannot be undone.')) {
        texts = [];
        images = [];
        shapes = [];
        selectedElement = null;
        document.getElementById('textElements').innerHTML = '';
        document.getElementById('imageElements').innerHTML = '';
        document.getElementById('shapeElements').innerHTML = '';
        initParts();
    }
});

// Back button with confirmation
document.getElementById('backBtn').addEventListener('click', () => {
    document.getElementById('backConfirmModal').style.display = 'flex';
});

document.getElementById('cancelBack').addEventListener('click', () => {
    document.getElementById('backConfirmModal').style.display = 'none';
});

document.getElementById('confirmBack').addEventListener('click', () => {
    window.history.back();
});

// Add to Cart
document.getElementById('addToCartBtn').addEventListener('click', () => {
    const dataUrl = canvas.toDataURL('image/png');
    const formData = new FormData();
    formData.append('item_id', <?php echo $item['id']; ?>);
    formData.append('quantity', 1);
    formData.append('design', dataUrl);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            alert('Design added to cart successfully!');
            window.location.href = 'my_cart.php';
        } else {
            alert('Failed to add to cart: ' + data.message);
        }
    })
    .catch(() => alert('Failed to add to cart: Server error'));
});

// Save design
document.getElementById('saveBtn').addEventListener('click', () => {
    alert('Design saved successfully!');
});
</script>

</body>
</html>