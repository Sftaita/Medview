import '@testing-library/jest-dom/vitest'
import { configure } from '@testing-library/react'

// Default findBy*/waitFor timeout (1000ms) is tight enough that a handful of
// GenerationModal tests flake under real container CPU load (observed
// non-deterministically across otherwise-identical runs, each test passing
// reliably in isolation) — never a logic bug, just too little headroom.
configure({ asyncUtilTimeout: 5000 })
