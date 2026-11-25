# Render the 360° rooms in HD on Google Colab (free GPU)

You need a Google account. Clicking takes about 5 minutes, then rendering takes about 1–1½ hours with the tab open.

Files in this folder:
- `paint-rooms.zip`: the room scenes (74 MB).
- `render-rooms-colab.ipynb`: the Colab notebook.

## A. Put the zip in Google Drive
1. Open **drive.google.com** and go to **My Drive**.
2. **+ New** → **New folder**, and name it exactly **paint-render**.
3. Open the folder and drag **paint-rooms.zip** into it. Wait until the upload says complete.

## B. Open the notebook in Colab
4. Open **colab.research.google.com**.
5. In the "Open notebook" window, choose **Upload**, then choose `render-rooms-colab.ipynb`.
   (If no window appears: **File** → **Upload notebook**.)

## C. Turn on the GPU
6. Menu **Runtime** → **Change runtime type** → **T4 GPU** → **Save**.

## D. Run
7. Menu **Runtime** → **Run all**.
8. A Google Drive pop-up appears. Click **Connect to Google Drive** and allow it.
9. Check the output:
   - Step 1 shows **Tesla T4** and "found …paint-rooms.zip".
   - Step 2 shows **Blender 4.2.23** and "project ready: True".
   - Step 3 prints a line every 30 seconds, for example `living: rendering…` and `3.0 min [living] rendering face r at 2048px`.
   - After each room: `living: done and saved to Drive`.
10. **Keep the Colab tab open** and stop the PC from sleeping until it finishes, about 10–15 minutes per room.

## E. Download
11. When step 4 prints `rooms-hd.zip`, go to **Google Drive → My Drive → paint-render**, right-click **rooms-hd.zip** and choose **Download**.
12. Put it in `E:\project\Paint-MVP\kaggle-render\` and tell Claude: "rooms zip is in".

## If Colab disconnects
Just do **Runtime → Run all** again. Rooms that are already saved in Drive are skipped, and it carries on with the next one.

If a step shows **FAILED** or a red error, copy the last lines into the chat.
