import type { User } from '../api'
import { AdminShell } from '../admin/AdminShell'

export function AdminPanel({ currentUser }: { currentUser: User }) {
  return <AdminShell currentUser={currentUser} />
}
