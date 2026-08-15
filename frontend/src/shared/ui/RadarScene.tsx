import { Edges, Line, RoundedBox } from '@react-three/drei'
import { Canvas, useFrame, useThree } from '@react-three/fiber'
import { Bloom, EffectComposer } from '@react-three/postprocessing'
import { useEffect, useRef } from 'react'
import type { Group } from 'three'

const RAILS = [
  { width: 3.05, y: 0.52, z: -0.16, selected: false },
  { width: 2.62, y: 0.26, z: -0.08, selected: false },
  { width: 2.28, y: 0, z: 0, selected: false },
  { width: 1.92, y: -0.26, z: 0.08, selected: false },
  { width: 1.48, y: -0.52, z: 0.16, selected: true },
] as const

const LEFT_EDGE = -1.9
const REVEAL_MS = 1450

function easeOutCubic(value: number) {
  const clamped = Math.min(1, Math.max(0, value))
  return 1 - Math.pow(1 - clamped, 3)
}

function PriceSorter() {
  const rails = useRef<Array<Group | null>>([])
  const startedAt = useRef(0)
  const { invalidate } = useThree()

  useEffect(() => {
    startedAt.current = performance.now()
    let frame = 0
    let lastFrame = 0

    const reveal = (now: number) => {
      if (now - lastFrame >= 32) {
        lastFrame = now
        invalidate()
      }
      if (now - startedAt.current < REVEAL_MS) frame = requestAnimationFrame(reveal)
      else invalidate()
    }

    invalidate()
    frame = requestAnimationFrame(reveal)
    return () => cancelAnimationFrame(frame)
  }, [invalidate])

  useFrame(() => {
    const elapsed = startedAt.current === 0 ? 0 : performance.now() - startedAt.current

    RAILS.forEach((rail, index) => {
      const group = rails.current[index]
      if (!group) return
      const progress = easeOutCubic((elapsed - 110 - index * 105) / 620)
      group.scale.x = Math.max(0.018, progress)
      group.position.x = LEFT_EDGE + (rail.width * progress) / 2
    })

  })

  return (
    <group position={[0.28, 0, 0]} rotation={[-0.012, -0.025, 0]}>
      <Line
        points={[[LEFT_EDGE - 0.16, 0.7, -0.2], [LEFT_EDGE - 0.16, -0.7, 0.2]]}
        color="#40515c"
        transparent
        opacity={0.36}
        lineWidth={0.85}
      />

      {RAILS.map((rail, index) => (
        <group
          key={rail.y}
          ref={(node) => { rails.current[index] = node }}
          position={[LEFT_EDGE + rail.width / 2, rail.y, rail.z]}
        >
          <RoundedBox args={[rail.width, 0.16, 0.09]} radius={0.04} smoothness={2}>
            <meshBasicMaterial
              color={rail.selected ? '#17382d' : '#17242c'}
              transparent
              opacity={rail.selected ? 0.96 : 0.82}
            />
            <Edges
              linewidth={0.75}
              threshold={20}
              color={rail.selected ? '#55d997' : '#526775'}
              transparent
              opacity={rail.selected ? 0.8 : 0.34}
            />
          </RoundedBox>
          <mesh position={[rail.width / 2 - 0.1, 0, 0.058]}>
            <circleGeometry args={[rail.selected ? 0.038 : 0.026, 20]} />
            <meshBasicMaterial
              color={rail.selected ? '#65dfa1' : '#6d8592'}
              transparent
              opacity={rail.selected ? 0.95 : 0.46}
            />
          </mesh>
          {rail.selected && (
            <mesh position={[rail.width / 2 - 0.1, 0, 0.06]}>
              <ringGeometry args={[0.072, 0.1, 32]} />
              <meshBasicMaterial color="#65dfa1" transparent opacity={0.68} />
            </mesh>
          )}
        </group>
      ))}
    </group>
  )
}

export default function RadarScene() {
  return (
    <Canvas
      orthographic
      frameloop="demand"
      dpr={[1, 1.4]}
      camera={{ position: [0, 0, 5], zoom: 50 }}
      gl={{ antialias: false, alpha: true, powerPreference: 'high-performance' }}
    >
      <PriceSorter />
      <EffectComposer multisampling={2} enableNormalPass={false}>
        <Bloom intensity={0.055} luminanceThreshold={0.78} luminanceSmoothing={0.12} mipmapBlur={false} />
      </EffectComposer>
    </Canvas>
  )
}
