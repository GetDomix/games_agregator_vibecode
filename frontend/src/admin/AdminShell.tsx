import { useCallback, useMemo, useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'
import type { User } from '../api'
import { AdminAuditTab } from './AdminAuditTab'
import { AdminCatalogTab } from './AdminCatalogTab'
import { AdminOverviewTab } from './AdminOverviewTab'
import { AdminTeamTab } from './AdminTeamTab'
import { AdminUsersTab } from './AdminUsersTab'

type AdminTab = 'overview' | 'catalog' | 'users' | 'team' | 'audit'
type TabDefinition = { id: AdminTab; label: string; hint: string }

const standardTabs: TabDefinition[] = [
  { id: 'overview', label: 'Обзор', hint: 'Состояние системы' },
  { id: 'catalog', label: 'Каталог', hint: 'Игры и источники' },
  { id: 'users', label: 'Пользователи', hint: 'Поиск аккаунтов' },
  { id: 'audit', label: 'Аудит', hint: 'История действий' },
]

const teamTab: TabDefinition = { id: 'team', label: 'Команда', hint: 'Роли и доступ' }

export function AdminShell({ currentUser }: { currentUser: User }) {
  const tabs = useMemo(() => {
    if (!currentUser.can_manage_admin_team) return standardTabs
    return [...standardTabs.slice(0, 3), teamTab, standardTabs[3]]
  }, [currentUser.can_manage_admin_team])
  const [selected, setSelected] = useState<AdminTab>('overview')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const tabRefs = useRef<Array<HTMLButtonElement | null>>([])

  const showError = useCallback((message: string) => {
    setError(message)
  }, [])

  const showNotice = useCallback((message: string) => {
    setNotice(message)
  }, [])

  const selectTab = (tab: AdminTab) => {
    setError('')
    setNotice('')
    setSelected(tab)
  }

  const navigateTabs = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    let next = index
    if (event.key === 'ArrowRight') next = (index + 1) % tabs.length
    else if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length
    else if (event.key === 'Home') next = 0
    else if (event.key === 'End') next = tabs.length - 1
    else return
    event.preventDefault()
    selectTab(tabs[next].id)
    tabRefs.current[next]?.focus()
  }

  return (
    <section className="section page-enter admin-console">
      <header className="admin-console-head">
        <div>
          <p className="eyebrow">Операционный радар</p>
          <h2>Пульт Игроскана</h2>
          <p className="muted">Контроль каталога, пользователей и фоновых процессов в защищённой админ-панели.</p>
        </div>
        <span className={`admin-role-chip ${currentUser.admin_role}`}>
          {currentUser.admin_role === 'owner' ? 'Владелец сайта' : 'Администратор'}
        </span>
      </header>

      <div className="admin-tab-rail" role="tablist" aria-label="Разделы админки">
        {tabs.map((tab, index) => (
          <button
            key={tab.id}
            ref={(node) => { tabRefs.current[index] = node }}
            id={`admin-tab-${tab.id}`}
            type="button"
            role="tab"
            aria-label={tab.label}
            aria-selected={selected === tab.id}
            aria-controls={`admin-panel-${tab.id}`}
            tabIndex={selected === tab.id ? 0 : -1}
            onClick={() => selectTab(tab.id)}
            onKeyDown={(event) => navigateTabs(event, index)}
          >
            <span>{tab.label}</span>
            <small>{tab.hint}</small>
          </button>
        ))}
      </div>

      {error && <div className="admin-message danger" role="alert">{error}</div>}
      {notice && <div className="admin-message ok" role="status">{notice}</div>}

      <div
        id={`admin-panel-${selected}`}
        role="tabpanel"
        aria-labelledby={`admin-tab-${selected}`}
        className="admin-tab-panel"
      >
        {selected === 'overview' && <AdminOverviewTab onError={showError} onNotice={showNotice} />}
        {selected === 'catalog' && <AdminCatalogTab onError={showError} onNotice={showNotice} />}
        {selected === 'users' && <AdminUsersTab onError={showError} onNotice={showNotice} />}
        {selected === 'team' && currentUser.can_manage_admin_team && (
          <AdminTeamTab currentUser={currentUser} onError={showError} onNotice={showNotice} />
        )}
        {selected === 'audit' && <AdminAuditTab onError={showError} onNotice={showNotice} />}
      </div>
    </section>
  )
}
