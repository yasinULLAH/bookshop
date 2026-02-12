import os
from PIL import Image, ImageDraw, ImageFont

# ---------------------------------------------------------
# CONFIGURATION
# ---------------------------------------------------------
# Define the mapping of Image Filenames -> Display Titles
IMAGE_MAP = {
    "dashboard_page_1769705115014.png": "Dashboard",
    "books_management_page_1769705164955.png": "Books Management",
    "customers_management_page_1769705208562.png": "Customers Management",
    "suppliers_management_page_1769705250612.png": "Suppliers Management",
    "purchase_orders_page_1769705306729.png": "Purchase Orders",
    "cart_and_sales_page_1769705368047.png": "Cart & Sales",
    "promotions_management_page_1769705429910.png": "Promotions",
    "expenses_management_page_1769705471428.png": "Expenses"
}

# Style Configuration
PADDING_TOP = 60          # Height of the new white space at top
RIBBON_HEIGHT = 40        # Height of the colored ribbon
RIBBON_COLOR = (44, 62, 80) # Dark Blue (matching documentation theme)
TEXT_COLOR = (255, 255, 255) # White
FONT_SIZE_RATIO = 0.5     # Text size relative to ribbon height

def annotate_images():
    print("--- Starting Image Annotation ---")
    
    # Get all files in current directory
    current_files = os.listdir('.')
    
    processed_count = 0
    
    for filename, title in IMAGE_MAP.items():
        if filename not in current_files:
            print(f"[SKIP] File not found: {filename}")
            continue
            
        try:
            # Open original image
            with Image.open(filename) as img:
                original_w, original_h = img.size
                
                # Create new blank image with extra top padding
                new_h = original_h + PADDING_TOP
                new_img = Image.new('RGB', (original_w, new_h), (255, 255, 255))
                
                # Paste original image below the padding
                new_img.paste(img, (0, PADDING_TOP))
                
                # Draw the Ribbon
                draw = ImageDraw.Draw(new_img)
                ribbon_y1 = (PADDING_TOP - RIBBON_HEIGHT) // 2
                ribbon_y2 = ribbon_y1 + RIBBON_HEIGHT
                
                # Draw ribbon rectangle (full width)
                draw.rectangle(
                    [(0, ribbon_y1), (original_w, ribbon_y2)], 
                    fill=RIBBON_COLOR
                )
                
                # Setup Font (Fallback to default if custom font fails)
                try:
                    # Try creating a font size relative to ribbon height
                    font_size = int(RIBBON_HEIGHT * FONT_SIZE_RATIO)
                    # Attempt to load Arial (Windows standard)
                    font = ImageFont.truetype("arial.ttf", font_size)
                except IOError:
                    # Fallback to default
                    font = ImageFont.load_default()
                
                # Calculate text position (Centered)
                # Using textbbox (newer Pillow versions) or textsize (older)
                text_text = f"MODULE: {title.upper()}"
                
                if hasattr(draw, 'textbbox'):
                    bbox = draw.textbbox((0, 0), text_text, font=font)
                    text_w = bbox[2] - bbox[0]
                    text_h = bbox[3] - bbox[1]
                else:
                    text_w, text_h = draw.textsize(text_text, font=font)
                
                text_x = (original_w - text_w) // 2
                # Center vertically in ribbon
                text_y = ribbon_y1 + (RIBBON_HEIGHT - text_h) // 2 - (text_h * 0.2) # minor baseline adjust
                
                draw.text((text_x, text_y), text_text, font=font, fill=TEXT_COLOR)
                
                # Save annotated version
                output_filename = f"annotated_{filename}"
                new_img.save(output_filename)
                
                print(f"[OK] Generated: {output_filename}")
                processed_count += 1
                
        except Exception as e:
            print(f"[ERROR] Could not process {filename}: {e}")

    print(f"--- Finished. Processed {processed_count} images. ---")

if __name__ == "__main__":
    annotate_images()