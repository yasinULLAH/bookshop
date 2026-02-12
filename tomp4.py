import os
from PIL import Image
import imageio
import numpy as np

SPEED_FACTOR = 0.57      # 🔥 0.57x slower
BASE_FPS = 10            # original fps

def make_even(img):
    w, h = img.size
    new_w = w if w % 2 == 0 else w + 1
    new_h = h if h % 2 == 0 else h + 1

    if (new_w, new_h) == (w, h):
        return img

    padded = Image.new("RGB", (new_w, new_h), (0, 0, 0))
    padded.paste(img, (0, 0))
    return padded

def webp_to_mp4(webp_path, mp4_path):
    img = Image.open(webp_path)
    frames = []

    try:
        while True:
            frame = img.convert("RGB")
            frame = make_even(frame)
            frames.append(np.array(frame))
            img.seek(img.tell() + 1)
    except EOFError:
        pass

    slowed_fps = BASE_FPS * SPEED_FACTOR

    imageio.mimsave(
        mp4_path,
        frames,
        fps=slowed_fps,
        codec="libx264",
        pixelformat="yuv420p"
    )

if __name__ == "__main__":
    for file in os.listdir("."):
        if file.lower().endswith(".webp"):
            mp4 = os.path.splitext(file)[0] + ".mp4"
            print(f"Converting (0.57x): {file} → {mp4}")
            webp_to_mp4(file, mp4)

    print("✅ All videos converted at 0.57× speed.")
