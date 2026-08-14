import type { User } from '../../shared/api/client'
import { AdminShell } from './AdminShell'

export function AdminPanel({ currentUser }: { currentUser: User }) {
  return <AdminShell currentUser={currentUser} />
}
