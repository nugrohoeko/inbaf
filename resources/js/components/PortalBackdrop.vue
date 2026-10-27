<template>
<div class="portal-backdrop" aria-hidden="true">
    <span class="portal-backdrop-glow portal-backdrop-glow-mint"></span>
    <span class="portal-backdrop-glow portal-backdrop-glow-lilac"></span>
    <span class="portal-backdrop-grid"></span>
</div>
</template>

<style scoped>
.portal-backdrop {
    position: absolute;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    pointer-events: none;
    opacity: var(--portal-backdrop-opacity, 1);
}

.portal-backdrop-grid {
    position: absolute;
    inset: -64px;
    background-image:
        linear-gradient(var(--portal-grid-color, rgba(185, 243, 202, .1)) 1px, transparent 1px),
        linear-gradient(90deg, var(--portal-grid-color, rgba(185, 243, 202, .1)) 1px, transparent 1px);
    background-size: 64px 64px;
    animation: portal-grid-drift 10s linear infinite;
}

.portal-backdrop-glow {
    position: absolute;
    width: clamp(16rem, 60%, 36rem);
    aspect-ratio: 1;
    border-radius: 50%;
    animation: portal-glow-drift 16s ease-in-out infinite alternate;
}

.portal-backdrop-glow-mint {
    top: -15%;
    right: 5%;
    background: radial-gradient(circle, rgba(108, 229, 187, .14), transparent 68%);
}

.portal-backdrop-glow-lilac {
    right: -15%;
    bottom: -35%;
    background: radial-gradient(circle, rgba(189, 171, 239, .1), transparent 68%);
    animation-duration: 20s;
    animation-direction: alternate-reverse;
}

@keyframes portal-grid-drift {
    from { transform: translate3d(0, 0, 0); }
    to { transform: translate3d(64px, 64px, 0); }
}

@keyframes portal-glow-drift {
    from { transform: translate3d(-2%, 0, 0); }
    to { transform: translate3d(5%, 5%, 0); }
}

@media (prefers-reduced-motion: reduce) {
    .portal-backdrop-grid,
    .portal-backdrop-glow {
        animation: none;
    }
}
</style>
