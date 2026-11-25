# Render the 360° rooms in HD on Kaggle (free GPU)

About 10 minutes of clicking, then 30–45 minutes of rendering while you wait.

Two files in this folder:
- `paint-rooms.zip` (74 MB): the room scenes, textures and furniture.
- `render-rooms.ipynb`: the notebook that renders the six rooms.

## Once only: account
1. Sign up at **kaggle.com**.
2. Verify your phone number: your profile picture → **Settings** → **Phone verification**. Kaggle needs this before it allows a GPU or internet access.

## Upload the scenes
3. Left menu → **Datasets** → **+ New Dataset**.
4. Drag in `paint-rooms.zip`, name it **paint-rooms**, keep it **Private**, then click **Create**.

## Run
5. Left menu → **Code** → **+ New Notebook**.
6. Menu **File** → **Import Notebook** → choose `render-rooms.ipynb`.
7. In the right-hand panel:
   - **Input** → **+ Add Input** → **Your Datasets** → **paint-rooms** → click **+**.
   - **Session options**: set **Accelerator** to **GPU T4 x2** and switch **Internet** on.
8. Click **Run All**. Step 3 prints progress every minute for both GPUs.
9. When step 4 prints `rooms-hd.zip`, open the **Output** panel (right side, `/kaggle/working`) and download **rooms-hd.zip**.

## Back here
10. Put `rooms-hd.zip` in this `kaggle-render` folder and tell Claude: "rooms zip is in". Claude installs it and checks every room.

If anything turns red, copy the last lines of the error into the chat.
