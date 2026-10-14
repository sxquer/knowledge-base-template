for (const widget of document.querySelectorAll<HTMLElement>(
    "[data-call-player]",
)) {
    const audio = widget.querySelector("audio");
    const words = Array.from(
        widget.querySelectorAll<HTMLButtonElement>("[data-start]"),
    );
    if (!audio || !words.length) continue;
    let active = -1;
    let frame = 0;
    const sync = () => {
        let left = 0,
            right = words.length - 1,
            index = -1;
        while (left <= right) {
            const mid = (left + right) >> 1;
            if (Number(words[mid].dataset.start) <= audio.currentTime) {
                index = mid;
                left = mid + 1;
            } else right = mid - 1;
        }
        if (index >= 0 && Number(words[index].dataset.end) < audio.currentTime)
            index = -1;
        if (index !== active) {
            if (active >= 0) words[active].classList.remove("active");
            if (index >= 0) words[index].classList.add("active");
            active = index;
        }
    };
    const tick = () => {
        sync();
        if (!audio.paused) frame = requestAnimationFrame(tick);
    };
    audio.addEventListener("play", () => {
        cancelAnimationFrame(frame);
        tick();
    });
    audio.addEventListener("pause", () => {
        cancelAnimationFrame(frame);
        sync();
    });
    audio.addEventListener("timeupdate", sync);
    for (const word of words)
        word.addEventListener("click", () => {
            audio.currentTime = Number(word.dataset.start);
            void audio.play().catch(() => {});
        });
}
